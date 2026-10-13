<?php
/**
 * Stock: Urbby es el único que descuenta.
 *
 * - Una venta de la tienda Woo aparta en Urbby al hacer el checkout y confirma cuando Woo la da por
 *   hecha (pago, «procesando»). Si Urbby no tiene stock, la venta no pasa.
 * - El stock que muestra Woo es una copia del de Urbby: se pone al día después de cada reserva y cada
 *   30 minutos.
 * - Un cambio a mano en Woo (el comercio recibió mercadería) viaja a Urbby como escritura condicional
 *   contra lo último que copiamos de Urbby: si Urbby se movió en el medio, se reaplica la diferencia
 *   del comercio sobre lo que hay, nunca se pisa una venta.
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reservas, confirmaciones y la copia local del stock.
 */
final class Urbby_WC_Stock {

	const META_SYNCED   = '_urbby_stock_synced';
	const META_PENDING  = '_urbby_stock_pending';
	const META_INFLIGHT = '_urbby_stock_inflight';

	const ORDER_RESERVATION = '_urbby_reservation_id';
	const ORDER_REFERENCE   = '_urbby_reservation_ref';
	const ORDER_BASE_REF    = '_urbby_reservation_base';
	const ORDER_STATE       = '_urbby_reservation_status';
	const ORDER_RETRIES     = '_urbby_retries';

	const ACTION_SYNC      = 'urbby_wc_sync_stock';
	const ACTION_SYNC_PAGE = 'urbby_wc_sync_stock_page';
	const ACTION_DELTA     = 'urbby_wc_push_delta';
	const ACTION_SETTLE    = 'urbby_wc_settle_order';
	const ACTION_RELEASE   = 'urbby_wc_release_order';
	const ACTION_RELEASE_R = 'urbby_wc_release_reservation';
	const ACTION_REFRESH   = 'urbby_wc_refresh_products';

	/** Estados en los que una venta de la tienda está hecha / caída. */
	const SOLD     = array( 'processing', 'completed', 'on-hold' );
	const RELEASED = array( 'cancelled', 'failed', 'refunded' );

	/**
	 * Mientras es true, los cambios de stock son nuestros (copia de Urbby) y no se reenvían a Urbby
	 * ni disparan una subida de catálogo.
	 *
	 * @var bool
	 */
	public static $writing = false;

	/** @var bool Corta la recursión de settle → update_status → settle. */
	private static $settling = false;

	/** @var array<string,int>|null Mapa variante de Urbby → producto de Woo, por request. */
	private static $map = null;

	public static function hooks() {
		add_filter( 'woocommerce_can_reduce_order_stock', array( __CLASS__, 'can_reduce' ), 20, 2 );
		add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', array( __CLASS__, 'prevent_adjust' ), 20, 2 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_set' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_set' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'reserve_classic' ) );
		// Después de validar dirección y carrito, antes del pago: una compra que Woo igual iba a rechazar no aparta nada.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'reserve_blocks' ) );
		foreach ( self::SOLD as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'settle' ) );
		}
		foreach ( self::RELEASED as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'release_for_order' ) );
		}
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'release_on_remove' ) );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'release_on_remove' ) );
		add_action( self::ACTION_SYNC, array( __CLASS__, 'sync_from_urbby' ) );
		add_action( self::ACTION_SYNC_PAGE, array( __CLASS__, 'sync_from_urbby' ) );
		add_action( self::ACTION_DELTA, array( __CLASS__, 'push_delta' ) );
		add_action( self::ACTION_SETTLE, array( __CLASS__, 'settle' ) );
		add_action( self::ACTION_RELEASE, array( __CLASS__, 'release_for_order' ) );
		add_action( self::ACTION_RELEASE_R, array( __CLASS__, 'release_reservation' ), 10, 2 );
		add_action( self::ACTION_REFRESH, array( __CLASS__, 'refresh_products' ) );
	}

	// ─────────────────────────────────────────────────────────── tareas en segundo plano

	/**
	 * Hay una tarea PENDIENTE igual. `as_has_scheduled_action` cuenta también la que está corriendo,
	 * y desde adentro de un reintento eso cortaba la cadena: se veía a sí misma y no reprogramaba.
	 *
	 * @param string $hook Tarea.
	 * @param array  $args Argumentos.
	 * @return bool
	 */
	public static function pending( $hook, array $args ) {
		return (bool) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => 'urbby',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
	}

	/**
	 * Encola una tarea si no hay otra igual pendiente.
	 *
	 * @param string $hook  Tarea.
	 * @param array  $args  Argumentos.
	 * @param int    $delay Segundos.
	 */
	public static function later( $hook, array $args, $delay ) {
		if ( ! self::pending( $hook, $args ) ) {
			as_schedule_single_action( time() + max( 1, (int) $delay ), $hook, $args, 'urbby' );
		}
	}

	/**
	 * Espera creciente: 1, 2, 4, 8 y después cada 15 minutos.
	 *
	 * @param int $attempt Intento (0 el primero).
	 * @return int Segundos.
	 */
	private static function backoff( $attempt ) {
		return min( 900, 60 * ( 2 ** min( 4, max( 0, (int) $attempt ) ) ) );
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
			$variant = self::variant_of_item( $item );
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
	 * @param WC_Order_Item $item Línea.
	 * @return string Variante de Urbby, o '' si la línea no está en Urbby.
	 */
	private static function variant_of_item( $item ) {
		$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
		return $product ? (string) get_post_meta( $product->get_id(), Urbby_WC_Catalog::META_VARIANT, true ) : '';
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
		$base     = Urbby_WC_Mapper::reservation_reference( $order->get_id(), $lines, home_url() );
		$previous = (string) $order->get_meta( self::ORDER_RESERVATION );
		if ( $previous && $order->get_meta( self::ORDER_BASE_REF ) === $base && 'held' === $order->get_meta( self::ORDER_STATE ) ) {
			return true;
		}
		if ( $previous && 'released' !== $order->get_meta( self::ORDER_STATE ) ) {
			Urbby_WC_Api::release( $previous );
		}

		$hold      = (int) Urbby_WC_Settings::get()['hold_minutes'];
		$reference = $base;
		$res       = Urbby_WC_Api::reserve( $reference, $lines, $hold );
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
		$order->update_meta_data( self::ORDER_RESERVATION, (string) $res->body['id'] );
		$order->update_meta_data( self::ORDER_REFERENCE, $reference );
		$order->update_meta_data( self::ORDER_BASE_REF, $base );
		$order->update_meta_data( self::ORDER_STATE, (string) $res->body['status'] );
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
	 * Guardar una orden en el admin (o editarla por la API REST) ajusta el stock de cada línea que no
	 * tenga `_reduced_stock`. Las de Urbby no lo tienen a propósito: sin esto, cada «Actualizar» de una
	 * orden descontaba otra vez y el descuento viajaba a Urbby como un cambio a mano.
	 *
	 * @param bool          $prevent Lo que decidieron otros.
	 * @param WC_Order_Item $item    Línea.
	 * @return bool
	 */
	public static function prevent_adjust( $prevent, $item ) {
		if ( $prevent || ! $item instanceof WC_Order_Item_Product ) {
			return $prevent;
		}
		$order = $item->get_order();
		if ( $order && $order->get_meta( Urbby_WC_Orders::META_FULFILLMENT ) ) {
			return true;
		}
		return '' !== self::variant_of_item( $item );
	}

	/**
	 * Da por hecha una venta de la tienda Woo: confirma su reserva (o aparta y confirma si no tenía,
	 * como una orden creada a mano en el admin) y descuenta localmente las líneas que no están en Urbby.
	 * Idempotente, y relee la orden: un reintento sobre una orden que mientras tanto se canceló no hace
	 * nada.
	 *
	 * @param int $order_id Orden.
	 */
	public static function settle( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( self::$settling || ! $order || $order->get_meta( Urbby_WC_Orders::META_FULFILLMENT ) || ! in_array( $order->get_status(), self::SOLD, true ) ) {
			return;
		}
		if ( ! self::urbby_lines( $order ) ) {
			return;
		}
		self::$settling = true;
		try {
			if ( 'confirmed' === $order->get_meta( self::ORDER_STATE ) ) {
				// Ya confirmada (p. ej. la orden volvió a «pendiente» y Woo devolvió sus líneas locales).
				self::reduce_local_lines( $order );
				return;
			}
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
				self::fail( self::ACTION_SETTLE, $order, $res, 'no se pudo confirmar el stock con Urbby' );
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
				self::fail( self::ACTION_SETTLE, $order, $reserved, 'no se pudo apartar el stock en Urbby' );
				return;
			}
			$res = Urbby_WC_Api::confirm( (string) $order->get_meta( self::ORDER_RESERVATION ) );
			if ( ! $res->ok() ) {
				self::fail( self::ACTION_SETTLE, $order, $res, 'no se pudo confirmar el stock con Urbby' );
				return;
			}
		}

		self::reduce_local_lines( $order );
		$order->update_meta_data( self::ORDER_STATE, 'confirmed' );
		$order->delete_meta_data( self::ORDER_RETRIES );
		$order->save();
		$order->add_order_note( 'Urbby: stock confirmado.' );
	}

	/**
	 * Lo que haría Woo con las líneas que no están en Urbby, respetando su ajuste global de inventario.
	 * Idempotente por `_reduced_stock` de cada línea. Marca la orden como descontada para que Woo, al
	 * cancelar, devuelva solo esas líneas.
	 *
	 * @param WC_Order $order Orden.
	 */
	private static function reduce_local_lines( WC_Order $order ) {
		if ( 'yes' === get_option( 'woocommerce_manage_stock' ) ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
				if ( ! $product || ! $product->managing_stock() || $item->get_meta( '_reduced_stock', true ) || '' !== self::variant_of_item( $item ) ) {
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
	 * Cancelada, fallida o reembolsada: devuelve en Urbby lo apartado o confirmado. Relee el estado:
	 * un reintento sobre una orden que mientras tanto se reabrió no suelta nada.
	 *
	 * @param int $order_id Orden.
	 */
	public static function release_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( Urbby_WC_Orders::META_FULFILLMENT ) || ! in_array( $order->get_status(), self::RELEASED, true ) ) {
			return;
		}
		$id = (string) $order->get_meta( self::ORDER_RESERVATION );
		if ( ! $id || 'released' === $order->get_meta( self::ORDER_STATE ) ) {
			return;
		}
		$res = Urbby_WC_Api::release( $id );
		if ( ! $res->ok() ) {
			self::fail( self::ACTION_RELEASE, $order, $res, 'no se pudo devolver el stock en Urbby' );
			return;
		}
		$order->update_meta_data( self::ORDER_STATE, 'released' );
		$order->delete_meta_data( self::ORDER_RETRIES );
		$order->save();
		$order->add_order_note( 'Urbby: stock devuelto.' );
		self::display_from_reservation( $res->body );
	}

	/**
	 * Una orden con su reserva viva que va a la papelera o se borra: la reserva se suelta por su id
	 * (la orden puede no existir cuando corra la tarea).
	 *
	 * @param int $order_id Orden.
	 */
	public static function release_on_remove( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( Urbby_WC_Orders::META_FULFILLMENT ) ) {
			return;
		}
		$id = (string) $order->get_meta( self::ORDER_RESERVATION );
		if ( $id && 'released' !== $order->get_meta( self::ORDER_STATE ) ) {
			self::later( self::ACTION_RELEASE_R, array( $id, 0 ), 1 );
		}
	}

	/**
	 * @param string $reservation_id Reserva.
	 * @param int    $attempt        Intento.
	 */
	public static function release_reservation( $reservation_id, $attempt = 0 ) {
		$res = Urbby_WC_Api::release( (string) $reservation_id );
		if ( $res->ok() ) {
			self::display_from_reservation( $res->body );
		} elseif ( $res->retryable() ) {
			self::later( self::ACTION_RELEASE_R, array( (string) $reservation_id, (int) $attempt + 1 ), self::backoff( (int) $attempt + 1 ) );
		}
	}

	/**
	 * Algo con Urbby falló: si se puede reintentar, se reintenta con espera creciente; si no (permiso,
	 * dato inválido) queda en espera con el motivo, para que lo vea una persona.
	 *
	 * @param string            $action Tarea a repetir.
	 * @param WC_Order          $order  Orden.
	 * @param Urbby_WC_Response $res    Lo que respondió Urbby.
	 * @param string            $why    Qué se intentaba.
	 */
	private static function fail( $action, WC_Order $order, Urbby_WC_Response $res, $why ) {
		Urbby_WC_Log::error(
			'Stock con Urbby',
			array(
				'order'  => $order->get_id(),
				'action' => $action,
				'status' => $res->status,
				'code'   => $res->code(),
			)
		);
		if ( ! $res->retryable() ) {
			$order->add_order_note( 'Urbby: ' . $why . ' (' . $res->message() . '). Revisalo antes de despachar.' );
			if ( self::ACTION_SETTLE === $action && 'on-hold' !== $order->get_status() ) {
				$order->update_status( 'on-hold' );
			}
			return;
		}
		$attempt = (int) $order->get_meta( self::ORDER_RETRIES );
		$order->update_meta_data( self::ORDER_RETRIES, $attempt + 1 );
		$order->save();
		if ( 0 === $attempt ) {
			$order->add_order_note( 'Urbby: ' . $why . ' (' . $res->message() . '). Se reintenta solo.' );
		}
		self::later( $action, array( $order->get_id() ), self::backoff( $attempt ) );
	}

	// ─────────────────────────────────────────────────────────── cambios a mano en Woo

	/**
	 * Un cambio de stock que no hicimos nosotros (el comercio lo editó, un importador) se encola para
	 * mandarlo a Urbby. En segundo plano: un importador de 500 productos no hace 500 llamadas en el
	 * request.
	 *
	 * @param WC_Product $product Producto o variación.
	 */
	public static function on_stock_set( $product ) {
		if ( self::$writing || ! $product instanceof WC_Product || true !== $product->get_manage_stock() || ! get_post_meta( $product->get_id(), Urbby_WC_Catalog::META_VARIANT, true ) ) {
			return;
		}
		if ( '' === (string) get_post_meta( $product->get_id(), self::META_SYNCED, true ) ) {
			return;
		}
		update_post_meta( $product->get_id(), self::META_PENDING, 'yes' );
		self::later( self::ACTION_DELTA, array( $product->get_id() ), 1 );
	}

	/**
	 * Manda a Urbby el número que puso el comercio, CONDICIONADO a que Urbby siga en lo último que
	 * copiamos (`set` + `expectedStock`). Si Urbby se movió (una venta), se reaplica la diferencia del
	 * comercio sobre lo que hay. Un reintento después de un timeout no puede aplicar dos veces: si el
	 * primero llegó, el condicional ya no coincide.
	 *
	 * @param int $product_id Producto o variación.
	 */
	public static function push_delta( $product_id ) {
		$product = wc_get_product( $product_id );
		$variant = $product ? (string) get_post_meta( $product_id, Urbby_WC_Catalog::META_VARIANT, true ) : '';
		if ( ! $variant || true !== $product->get_manage_stock() ) {
			self::done_delta( $product_id );
			return;
		}
		$expected = (int) get_post_meta( $product_id, self::META_SYNCED, true );
		$target   = (int) $product->get_stock_quantity();
		if ( $target === $expected ) {
			self::done_delta( $product_id );
			return;
		}

		for ( $i = 0; $i < 3; $i++ ) {
			$target = max( 0, $target );
			$res    = Urbby_WC_Api::bulk_stock(
				array(
					array(
						'variantId'     => $variant,
						'mode'          => 'set',
						'amount'        => $target,
						'expectedStock' => $expected,
					),
				)
			);
			if ( ! $res->ok() ) {
				if ( $res->retryable() ) {
					// Sin respuesta no se sabe si llegó: el próximo intento lo descubre por el condicional.
					update_post_meta( $product_id, self::META_INFLIGHT, 'yes' );
					self::later( self::ACTION_DELTA, array( (int) $product_id ), 120 );
				} else {
					update_post_meta( $product_id, Urbby_WC_Catalog::META_ERROR, 'Urbby no aceptó el cambio de stock: ' . $res->message() );
					self::done_delta( $product_id );
				}
				return;
			}
			$row = isset( $res->body['results'][0] ) ? $res->body['results'][0] : array();
			if ( isset( $row['status'] ) && 'applied' === $row['status'] ) {
				self::write_display( $product, (int) $row['stock'] );
				self::done_delta( $product_id );
				return;
			}
			$code    = isset( $row['error']['code'] ) ? $row['error']['code'] : '';
			$current = isset( $row['error']['details']['currentStock'] ) ? (int) $row['error']['details']['currentStock'] : null;
			if ( 'STOCK_CHANGED' !== $code || null === $current ) {
				update_post_meta( $product_id, Urbby_WC_Catalog::META_ERROR, 'Urbby no aceptó el cambio de stock: ' . ( isset( $row['error']['message'] ) ? $row['error']['message'] : 'error' ) );
				self::done_delta( $product_id );
				return;
			}
			if ( $current === $target ) {
				// Un intento anterior sin respuesta sí había llegado.
				self::write_display( $product, $current );
				self::done_delta( $product_id );
				return;
			}
			if ( get_post_meta( $product_id, self::META_INFLIGHT, true ) ) {
				// Hubo un intento sin respuesta y Urbby además se movió: no hay forma de saber si aquel llegó.
				update_post_meta( $product_id, Urbby_WC_Catalog::META_ERROR, 'No sabemos si Urbby recibió tu último cambio de stock (de ' . $expected . ' a ' . $target . '). Revisá el stock de este producto en Urbby.' );
				self::write_display( $product, $current );
				self::done_delta( $product_id );
				return;
			}
			// Urbby vendió o apartó mientras tanto: la diferencia del comercio va sobre lo que hay.
			$target   = $current + ( $target - $expected );
			$expected = $current;
		}
		self::later( self::ACTION_DELTA, array( (int) $product_id ), 60 );
	}

	/**
	 * @param int $product_id Producto o variación.
	 */
	private static function done_delta( $product_id ) {
		delete_post_meta( $product_id, self::META_PENDING );
		delete_post_meta( $product_id, self::META_INFLIGHT );
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
	 * @param array|null $reservation Reserva de Urbby.
	 */
	private static function display_from_reservation( $reservation ) {
		foreach ( is_array( $reservation ) && isset( $reservation['lines'] ) ? (array) $reservation['lines'] : array() as $line ) {
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
	 * Variante de Urbby → producto o variación de Woo. Una consulta por request.
	 *
	 * @return array<string,int>
	 */
	public static function variant_map() {
		if ( null !== self::$map ) {
			return self::$map;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Un solo SELECT por índice de meta_key; no hay API para leer este mapa entero.
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", Urbby_WC_Catalog::META_VARIANT ) );
		self::$map = array();
		foreach ( $rows as $row ) {
			// Ante un duplicado gana el más viejo: el original, no una copia.
			if ( ! isset( self::$map[ $row->meta_value ] ) ) {
				self::$map[ $row->meta_value ] = (int) $row->post_id;
			}
		}
		return self::$map;
	}

	/** Olvida el mapa del request (se vinculó o desvinculó una variante). */
	public static function forget_map() {
		self::$map = null;
	}

	/**
	 * Trae de Urbby el stock de esos productos y lo copia a Woo. Una llamada por producto.
	 *
	 * @param string[] $urbby_product_ids Productos de Urbby.
	 */
	public static function refresh_products( $urbby_product_ids ) {
		foreach ( (array) $urbby_product_ids as $id ) {
			$res = Urbby_WC_Api::product( (string) $id );
			if ( ! $res->ok() ) {
				continue; // La sincronización de cada 30 min lo cubre.
			}
			foreach ( (array) $res->body['variants'] as $variant ) {
				self::write_display_for_variant( $variant['id'], (int) $variant['stock'] );
			}
		}
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
