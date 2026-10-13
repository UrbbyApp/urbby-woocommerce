<?php
/**
 * Stock: Urbby es el único que descuenta.
 *
 * - Una venta de la tienda Woo aparta en Urbby al hacer el checkout y confirma cuando Woo la da por
 *   hecha (pago, «procesando»). Si Urbby no tiene stock, la venta no pasa.
 * - El stock que muestra Woo es una copia del de Urbby: se pone al día después de cada reserva y cada
 *   30 minutos.
 * - Un cambio a mano en Woo (el comercio recibió mercadería) viaja a Urbby como diferencia, nunca
 *   como número absoluto: un absoluto pisaría las ventas que entraron mientras tanto.
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reservas, confirmaciones y la copia local del stock.
 */
final class Urbby_WC_Stock {

	const META_SYNCED  = '_urbby_stock_synced';
	const META_PENDING = '_urbby_stock_pending';

	const ORDER_RESERVATION = '_urbby_reservation_id';
	const ORDER_REFERENCE   = '_urbby_reservation_ref';
	const ORDER_STATE       = '_urbby_reservation_status';

	const ACTION_SYNC      = 'urbby_wc_sync_stock';
	const ACTION_SYNC_PAGE = 'urbby_wc_sync_stock_page';
	const ACTION_DELTA     = 'urbby_wc_push_delta';
	const ACTION_SETTLE    = 'urbby_wc_settle_order';
	const ACTION_RELEASE   = 'urbby_wc_release_order';

	/**
	 * Mientras es true, los cambios de stock son nuestros (copia de Urbby) y no se reenvían a Urbby
	 * ni disparan una subida de catálogo.
	 *
	 * @var bool
	 */
	public static $writing = false;

	/** @var bool Corta la recursión de settle → update_status → settle. */
	private static $settling = false;

	public static function hooks() {
		add_filter( 'woocommerce_can_reduce_order_stock', array( __CLASS__, 'can_reduce' ), 20, 2 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_set' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_set' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'reserve_classic' ) );
		// Después de validar dirección y carrito, antes del pago: una compra que Woo igual iba a rechazar no aparta nada.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'reserve_blocks' ) );
		foreach ( array( 'processing', 'completed', 'on-hold' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'settle' ) );
		}
		foreach ( array( 'cancelled', 'failed', 'refunded' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'release_for_order' ) );
		}
		add_action( self::ACTION_SYNC, array( __CLASS__, 'sync_from_urbby' ) );
		add_action( self::ACTION_SYNC_PAGE, array( __CLASS__, 'sync_from_urbby' ) );
		add_action( self::ACTION_DELTA, array( __CLASS__, 'push_delta' ) );
		add_action( self::ACTION_SETTLE, array( __CLASS__, 'settle' ) );
		add_action( self::ACTION_RELEASE, array( __CLASS__, 'release_for_order' ) );
	}

	// ─────────────────────────────────────────────────────────── ventas de la tienda Woo

	/**
	 * Las líneas de la orden que tienen stock en Urbby, con la variante de cada una.
	 *
	 * @param WC_Order $order Orden de Woo.
	 * @return array `[[ 'variantId', 'quantity' ], …]`, la misma variante sumada.
	 */
	public static function urbby_lines( WC_Order $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
			$variant = $product ? get_post_meta( $product->get_id(), Urbby_WC_Catalog::META_VARIANT, true ) : '';
			if ( $variant ) {
				$lines[] = array(
					'variantId' => $variant,
					'quantity'  => (int) $item->get_quantity(),
				);
			}
		}
		return Urbby_WC_Mapper::merge_lines( $lines );
	}

	/**
	 * Aparta en Urbby lo que pide la orden. Idempotente: la misma orden con las mismas líneas no aparta
	 * dos veces; si cambiaron las líneas, suelta la reserva anterior.
	 *
	 * @param WC_Order $order Orden.
	 * @return true|Urbby_WC_Response true si quedó apartado (o no había nada de Urbby).
	 */
	public static function reserve( WC_Order $order ) {
		$lines = self::urbby_lines( $order );
		if ( ! $lines ) {
			return true;
		}
		$reference = Urbby_WC_Mapper::reservation_reference( $order->get_id(), $lines );
		$previous  = (string) $order->get_meta( self::ORDER_RESERVATION );
		if ( $previous && $order->get_meta( self::ORDER_REFERENCE ) === $reference && 'held' === $order->get_meta( self::ORDER_STATE ) ) {
			return true;
		}
		if ( $previous ) {
			Urbby_WC_Api::release( $previous );
		}

		$hold = (int) Urbby_WC_Settings::get()['hold_minutes'];
		$res  = Urbby_WC_Api::reserve( $reference, $lines, $hold );
		if ( $res->ok() && 'released' === $res->body['status'] ) {
			// Esa reference ya se usó y venció (el cliente volvió horas después): otra reference.
			$reference .= '-' . time();
			$res        = Urbby_WC_Api::reserve( $reference, $lines, $hold );
		}
		if ( ! $res->ok() ) {
			if ( 'STOCK_INSUFFICIENT' === $res->code() ) {
				foreach ( (array) $res->body['details']['lines'] as $short ) {
					self::write_display_for_variant( $short['variantId'], (int) $short['available'] );
				}
			}
			return $res;
		}
		$order->update_meta_data( self::ORDER_RESERVATION, $res->body['id'] );
		$order->update_meta_data( self::ORDER_REFERENCE, $reference );
		$order->update_meta_data( self::ORDER_STATE, $res->body['status'] );
		$order->save();
		self::display_from_reservation( $res->body );
		return true;
	}

	/**
	 * El mensaje para el cliente cuando no se pudo apartar.
	 *
	 * @param Urbby_WC_Response $res Respuesta de Urbby.
	 * @return string
	 */
	public static function checkout_message( Urbby_WC_Response $res ) {
		if ( 'STOCK_INSUFFICIENT' !== $res->code() ) {
			return 'No pudimos confirmar el stock de tu pedido. Probá de nuevo en un momento.';
		}
		$names = array();
		foreach ( (array) $res->body['details']['lines'] as $short ) {
			$product = self::product_for_variant( $short['variantId'] );
			$names[] = ( $product ? $product->get_name() : 'un producto' ) . ' (quedan ' . (int) $short['available'] . ')';
		}
		return 'No hay stock suficiente de: ' . implode( ', ', $names ) . '.';
	}

	/**
	 * Checkout clásico: la orden ya existe; si no hay stock se marca fallida y el cliente ve el error.
	 *
	 * @param WC_Order $order Orden recién creada.
	 * @throws Exception Para que WooCommerce corte el checkout.
	 */
	public static function reserve_classic( $order ) {
		$result = self::reserve( $order );
		if ( true === $result ) {
			return;
		}
		$message = self::checkout_message( $result );
		$order->update_status( 'failed', 'Urbby: ' . $message );
		throw new Exception( esc_html( $message ) );
	}

	/**
	 * Checkout por bloques: la orden es un borrador que se reutiliza si el cliente reintenta.
	 *
	 * @param WC_Order $order Borrador.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Para que el checkout muestre el error.
	 */
	public static function reserve_blocks( $order ) {
		$result = self::reserve( $order );
		if ( true !== $result ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'urbby_stock', esc_html( self::checkout_message( $result ) ), 409 );
		}
	}

	/**
	 * Woo no descuenta por su cuenta las líneas que tienen stock en Urbby: lo hace `settle`, contra Urbby.
	 *
	 * @param bool     $can   Lo que decidió Woo.
	 * @param WC_Order $order Orden.
	 * @return bool
	 */
	public static function can_reduce( $can, $order ) {
		if ( ! $can || ! $order instanceof WC_Order ) {
			return $can;
		}
		if ( $order->get_meta( Urbby_WC_Orders::META_FULFILLMENT ) ) {
			return false; // Pedido de Urbby: Urbby ya descontó al vender.
		}
		if ( ! self::urbby_lines( $order ) ) {
			return $can;
		}
		self::settle( $order->get_id() );
		return false;
	}

	/**
	 * Da por hecha una venta de la tienda Woo: confirma su reserva (o aparta y confirma si no tenía,
	 * como una orden creada a mano en el admin) y descuenta localmente las líneas que no están en Urbby.
	 * Idempotente.
	 *
	 * @param int $order_id Orden.
	 */
	public static function settle( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( self::$settling || ! $order || $order->get_meta( Urbby_WC_Orders::META_FULFILLMENT ) || 'confirmed' === $order->get_meta( self::ORDER_STATE ) ) {
			return;
		}
		if ( ! self::urbby_lines( $order ) ) {
			return;
		}
		self::$settling = true;
		try {
			self::settle_locked( $order );
		} finally {
			self::$settling = false;
		}
	}

	/**
	 * @param WC_Order $order Orden.
	 */
	private static function settle_locked( WC_Order $order ) {
		$id = (string) $order->get_meta( self::ORDER_RESERVATION );
		if ( $id && 'held' === $order->get_meta( self::ORDER_STATE ) ) {
			$res = Urbby_WC_Api::confirm( $id );
			if ( 'RESERVATION_RELEASED' === $res->code() ) {
				// Venció antes del pago: se vuelve a apartar.
				$order->update_meta_data( self::ORDER_STATE, 'released' );
				$order->save();
				$id = '';
			} elseif ( ! $res->ok() ) {
				self::retry( self::ACTION_SETTLE, $order, 'no se pudo confirmar el stock con Urbby (' . $res->message() . ')' );
				return;
			}
		}
		if ( ! $id || 'held' !== $order->get_meta( self::ORDER_STATE ) ) {
			$reserved = self::reserve( $order );
			if ( true !== $reserved ) {
				if ( 'STOCK_INSUFFICIENT' === $reserved->code() ) {
					$order->update_status( 'on-hold', 'Urbby no tiene stock para esta venta: ' . self::checkout_message( $reserved ) . ' No la despaches sin revisar.' );
					return;
				}
				self::retry( self::ACTION_SETTLE, $order, 'no se pudo apartar el stock en Urbby (' . $reserved->message() . ')' );
				return;
			}
			$res = Urbby_WC_Api::confirm( (string) $order->get_meta( self::ORDER_RESERVATION ) );
			if ( ! $res->ok() ) {
				self::retry( self::ACTION_SETTLE, $order, 'no se pudo confirmar el stock con Urbby (' . $res->message() . ')' );
				return;
			}
		}

		self::reduce_local_lines( $order );
		$order->update_meta_data( self::ORDER_STATE, 'confirmed' );
		$order->save();
		$order->add_order_note( 'Urbby: stock confirmado.' );
	}

	/**
	 * Lo que haría Woo con las líneas que no están en Urbby, respetando su ajuste global de inventario.
	 * Marca la orden como descontada para que Woo, al cancelar, devuelva solo esas líneas.
	 *
	 * @param WC_Order $order Orden.
	 */
	private static function reduce_local_lines( WC_Order $order ) {
		if ( 'yes' === get_option( 'woocommerce_manage_stock' ) ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
				if ( ! $product || ! $product->managing_stock() || $item->get_meta( '_reduced_stock', true ) || get_post_meta( $product->get_id(), Urbby_WC_Catalog::META_VARIANT, true ) ) {
					continue;
				}
				wc_update_product_stock( $product, $item->get_quantity(), 'decrease' );
				$item->add_meta_data( '_reduced_stock', $item->get_quantity(), true );
				$item->save();
			}
		}
		$order->get_data_store()->set_stock_reduced( $order->get_id(), true );
	}

	/**
	 * Cancelada, fallida o reembolsada: devuelve en Urbby lo que se había apartado o confirmado.
	 *
	 * @param int $order_id Orden.
	 */
	public static function release_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( Urbby_WC_Orders::META_FULFILLMENT ) ) {
			return;
		}
		$id = (string) $order->get_meta( self::ORDER_RESERVATION );
		if ( ! $id || 'released' === $order->get_meta( self::ORDER_STATE ) ) {
			return;
		}
		$res = Urbby_WC_Api::release( $id );
		if ( ! $res->ok() ) {
			self::retry( self::ACTION_RELEASE, $order, 'no se pudo devolver el stock en Urbby (' . $res->message() . ')' );
			return;
		}
		$order->update_meta_data( self::ORDER_STATE, 'released' );
		$order->save();
		$order->add_order_note( 'Urbby: stock devuelto.' );
		self::display_from_reservation( $res->body );
	}

	/**
	 * Reintenta en un minuto y lo deja anotado en la orden (una sola vez por motivo).
	 *
	 * @param string   $action Acción a repetir.
	 * @param WC_Order $order  Orden.
	 * @param string   $why    Motivo.
	 */
	private static function retry( $action, WC_Order $order, $why ) {
		$args = array( $order->get_id() );
		if ( ! as_has_scheduled_action( $action, $args, 'urbby' ) ) {
			as_schedule_single_action( time() + 60, $action, $args, 'urbby' );
			$order->add_order_note( 'Urbby: ' . $why . '. Se reintenta en un minuto.' );
		}
		Urbby_WC_Log::error(
			'Reintento de stock',
			array(
				'order'  => $order->get_id(),
				'action' => $action,
			)
		);
	}

	// ─────────────────────────────────────────────────────────── cambios a mano en Woo

	/**
	 * Un cambio de stock que no hicimos nosotros (el comercio lo editó, un importador) se manda a Urbby
	 * como diferencia contra lo último que copiamos de Urbby.
	 *
	 * @param WC_Product $product Producto o variación.
	 */
	public static function on_stock_set( $product ) {
		if ( self::$writing || ! $product instanceof WC_Product || ! get_post_meta( $product->get_id(), Urbby_WC_Catalog::META_VARIANT, true ) ) {
			return;
		}
		if ( '' === (string) get_post_meta( $product->get_id(), self::META_SYNCED, true ) ) {
			return;
		}
		update_post_meta( $product->get_id(), self::META_PENDING, 'yes' );
		self::push_delta( $product->get_id() );
	}

	/**
	 * @param int $product_id Producto o variación.
	 */
	public static function push_delta( $product_id ) {
		$product = wc_get_product( $product_id );
		$variant = $product ? (string) get_post_meta( $product_id, Urbby_WC_Catalog::META_VARIANT, true ) : '';
		if ( ! $variant ) {
			delete_post_meta( $product_id, self::META_PENDING );
			return;
		}
		$delta = (int) $product->get_stock_quantity() - (int) get_post_meta( $product_id, self::META_SYNCED, true );
		if ( 0 === $delta ) {
			delete_post_meta( $product_id, self::META_PENDING );
			return;
		}
		$res = Urbby_WC_Api::bulk_stock( array( Urbby_WC_Mapper::stock_delta_row( $variant, $delta ) ) );
		if ( ! $res->ok() ) {
			if ( $res->retryable() && ! as_has_scheduled_action( self::ACTION_DELTA, array( (int) $product_id ), 'urbby' ) ) {
				as_schedule_single_action( time() + 60, self::ACTION_DELTA, array( (int) $product_id ), 'urbby' );
			}
			Urbby_WC_Log::error(
				'No se pudo mandar un cambio de stock a Urbby.',
				array(
					'product' => $product_id,
					'status'  => $res->status,
				)
			);
			return;
		}
		$row = isset( $res->body['results'][0] ) ? $res->body['results'][0] : array();
		delete_post_meta( $product_id, self::META_PENDING );
		if ( isset( $row['status'] ) && 'applied' === $row['status'] ) {
			self::write_display( $product, (int) $row['stock'] );
			return;
		}
		// Urbby vendió en el medio y la resta dejaría negativo: manda lo que hay en Urbby.
		$current = isset( $row['error']['details']['currentStock'] ) ? (int) $row['error']['details']['currentStock'] : null;
		update_post_meta( $product_id, Urbby_WC_Catalog::META_ERROR, 'Urbby no aceptó el cambio de stock: ' . ( isset( $row['error']['message'] ) ? $row['error']['message'] : 'error' ) );
		if ( null !== $current ) {
			self::write_display( $product, $current );
		}
	}

	// ─────────────────────────────────────────────────────────── copia local del stock de Urbby

	/**
	 * Deja el stock de Woo igual al de Urbby, sin que vuelva a Urbby.
	 *
	 * @param WC_Product $product Producto o variación.
	 * @param int        $stock   Stock de Urbby.
	 */
	public static function write_display( WC_Product $product, $stock ) {
		self::$writing = true;
		try {
			if ( true !== $product->get_manage_stock() ) {
				$product->set_manage_stock( true );
				$product->save();
			}
			wc_update_product_stock( $product, max( 0, (int) $stock ), 'set' );
			update_post_meta( $product->get_id(), self::META_SYNCED, max( 0, (int) $stock ) );
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * Trae de Urbby el stock de esos productos y lo copia a Woo. Una llamada por producto.
	 *
	 * @param string[] $urbby_product_ids Productos de Urbby.
	 */
	public static function refresh_products( array $urbby_product_ids ) {
		foreach ( $urbby_product_ids as $id ) {
			$res = Urbby_WC_Api::product( $id );
			if ( ! $res->ok() ) {
				continue; // La sincronización de cada 30 min lo cubre.
			}
			foreach ( (array) $res->body['variants'] as $variant ) {
				self::write_display_for_variant( $variant['id'], (int) $variant['stock'] );
			}
		}
	}

	/**
	 * @param array $reservation Reserva de Urbby.
	 */
	private static function display_from_reservation( array $reservation ) {
		foreach ( isset( $reservation['lines'] ) ? (array) $reservation['lines'] : array() as $line ) {
			self::write_display_for_variant( $line['variantId'], (int) $line['stock'] );
		}
	}

	/**
	 * @param string $variant_id Variante de Urbby.
	 * @param int    $stock      Stock de Urbby.
	 */
	private static function write_display_for_variant( $variant_id, $stock ) {
		$product = self::product_for_variant( $variant_id );
		if ( $product && ! get_post_meta( $product->get_id(), self::META_PENDING, true ) ) {
			self::write_display( $product, $stock );
		}
	}

	/**
	 * @param string $variant_id Variante de Urbby.
	 * @return WC_Product|null
	 */
	public static function product_for_variant( $variant_id ) {
		$map = self::variant_map();
		return isset( $map[ $variant_id ] ) ? wc_get_product( $map[ $variant_id ] ) : null;
	}

	/**
	 * Variante de Urbby → producto o variación de Woo. Una consulta para todo el catálogo.
	 *
	 * @return array<string,int>
	 */
	public static function variant_map() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Un solo SELECT por índice de meta_key; no hay API para leer este mapa entero.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", Urbby_WC_Catalog::META_VARIANT ) );
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ $row->meta_value ] = (int) $row->post_id;
		}
		return $map;
	}

	/**
	 * Copia el stock de Urbby a Woo, una página de 100 productos por vez (cada página encola la que
	 * sigue). Se salta lo que tiene un cambio local esperando subir.
	 *
	 * @param string|null $cursor Página.
	 */
	public static function sync_from_urbby( $cursor = null ) {
		if ( ! Urbby_WC_Settings::connected() ) {
			return;
		}
		$res = Urbby_WC_Api::products( $cursor ? (string) $cursor : null );
		if ( ! $res->ok() ) {
			Urbby_WC_Log::error(
				'No se pudo leer el stock de Urbby.',
				array(
					'status' => $res->status,
					'code'   => $res->code(),
				)
			);
			return;
		}
		$map = self::variant_map();
		foreach ( (array) $res->body['data'] as $product ) {
			foreach ( (array) $product['variants'] as $variant ) {
				if ( ! isset( $map[ $variant['id'] ] ) || get_post_meta( $map[ $variant['id'] ], self::META_PENDING, true ) ) {
					continue;
				}
				$woo = wc_get_product( $map[ $variant['id'] ] );
				if ( $woo && ( (int) $woo->get_stock_quantity() !== (int) $variant['stock'] || (string) get_post_meta( $woo->get_id(), self::META_SYNCED, true ) !== (string) (int) $variant['stock'] ) ) {
					self::write_display( $woo, (int) $variant['stock'] );
				}
			}
		}
		if ( ! empty( $res->body['nextCursor'] ) ) {
			as_schedule_single_action( time() + 1, self::ACTION_SYNC_PAGE, array( (string) $res->body['nextCursor'] ), 'urbby' );
		}
	}
}
