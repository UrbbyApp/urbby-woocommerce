<?php
/**
 * Pedidos de Urbby → órdenes de Woo, y su estado.
 *
 * La orden de Woo es un registro para que el comercio vea y despache todo desde un lugar: no cobra
 * (el cobro y la liquidación viven en Urbby), no descuenta stock (ya lo descontó Urbby al vender) y
 * va al precio de la compra, sin los descuentos de Urbby: las promociones de Urbby no tocan Woo.
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receptor de avisos firmados de Urbby y reconciliación periódica.
 */
final class Urbby_WC_Orders {

	const META_FULFILLMENT = '_urbby_fulfillment_id';
	const META_NUMBER      = '_urbby_order_number';
	const META_VERSION     = '_urbby_version';
	const ACTION_RECONCILE = 'urbby_wc_reconcile_orders';

	/** Cuántas páginas de 50 mira la reconciliación: los pedidos de los últimos días. */
	const RECONCILE_PAGES = 4;

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( self::ACTION_RECONCILE, array( __CLASS__, 'reconcile' ) );
	}

	public static function routes() {
		register_rest_route(
			'urbby/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				// Pública a propósito: la autenticación es la firma HMAC del cuerpo, que se verifica adentro.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * `POST /wp-json/urbby/v1/webhook`. Sin firma válida, 401 sin detalle.
	 *
	 * @param WP_REST_Request $request Aviso.
	 * @return WP_REST_Response
	 */
	public static function webhook( WP_REST_Request $request ) {
		$body = $request->get_body();
		if ( ! Urbby_WC_Mapper::verify_signature( $body, (string) $request->get_header( 'x-urbby-signature' ), Urbby_WC_Settings::get()['webhook_secret'] ) ) {
			return new WP_REST_Response( array( 'code' => 'INVALID_SIGNATURE' ), 401 );
		}
		$event = json_decode( $body, true );
		$name  = isset( $event['event'] ) ? $event['event'] : '';
		if ( ! in_array( $name, array( 'order_created', 'order_status_changed', 'shipment_status_changed' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 ); // ping, payout_paid: nada que hacer.
		}

		$fulfillment = isset( $event['order'] ) && is_array( $event['order'] ) ? $event['order'] : null;
		if ( ! $fulfillment && ! empty( $event['fulfillmentId'] ) ) {
			$res = Urbby_WC_Api::order( (string) $event['fulfillmentId'] );
			if ( ! $res->ok() ) {
				return new WP_REST_Response( array( 'code' => 'URBBY_UNAVAILABLE' ), 503 ); // Urbby reintenta.
			}
			$fulfillment = $res->body;
		}
		if ( ! $fulfillment ) {
			return new WP_REST_Response( array( 'code' => 'NO_ORDER' ), 422 );
		}

		$result = self::upsert( $fulfillment, ! empty( $event['sandbox'] ) );
		if ( 'busy' === $result ) {
			return new WP_REST_Response( array( 'code' => 'BUSY' ), 503 ); // Otro aviso del mismo pedido en curso.
		}
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'result' => $result,
			),
			200
		);
	}

	/**
	 * La orden de Woo de un pedido de Urbby. Crea o actualiza; un aviso viejo no pisa uno nuevo.
	 *
	 * @param array $f       Pedido (`MerchantFulfillment`).
	 * @param bool  $sandbox Compra de prueba.
	 * @return string created | updated | unchanged | busy
	 */
	public static function upsert( array $f, $sandbox = false ) {
		$lock = 'urbby_wc_lock_' . md5( $f['id'] );
		// add_option es atómico (option_name es único): sirve de candado entre dos avisos simultáneos.
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			if ( (int) get_option( $lock ) > time() - 60 ) {
				return 'busy';
			}
			update_option( $lock, time(), false ); // Candado de un proceso que murió.
		}
		try {
			$order = self::find( $f['id'] );
			if ( ! $order ) {
				self::create( $f, $sandbox );
				return 'created';
			}
			$version = Urbby_WC_Mapper::version( $f );
			if ( strcmp( $version, (string) $order->get_meta( self::META_VERSION ) ) <= 0 ) {
				return 'unchanged';
			}
			$order->update_meta_data( self::META_VERSION, $version );
			self::apply_status( $order, $f );
			$order->save();
			return 'updated';
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * @param string $fulfillment_id Pedido de Urbby.
	 * @return WC_Order|null
	 */
	public static function find( $fulfillment_id ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => self::META_FULFILLMENT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $fulfillment_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'status'     => 'any',
			)
		);
		return $orders ? $orders[0] : null;
	}

	/**
	 * @param array $f       Pedido.
	 * @param bool  $sandbox Compra de prueba.
	 */
	private static function create( array $f, $sandbox ) {
		$order = wc_create_order( array( 'created_via' => 'urbby' ) );
		$order->update_meta_data( self::META_FULFILLMENT, $f['id'] );
		$order->update_meta_data( self::META_NUMBER, $f['orderNumber'] );
		$order->update_meta_data( self::META_VERSION, Urbby_WC_Mapper::version( $f ) );

		$address              = isset( $f['shippingAddress'] ) ? $f['shippingAddress'] : array();
		list( $first, $last ) = Urbby_WC_Mapper::split_name( isset( $address['fullName'] ) ? $address['fullName'] : '' );
		$fields               = array(
			'first_name' => $first,
			'last_name'  => $last,
			'address_1'  => isset( $address['line1'] ) ? $address['line1'] : '',
			'address_2'  => isset( $address['reference'] ) ? (string) $address['reference'] : '',
			'city'       => isset( $address['city'] ) ? $address['city'] : '',
			'state'      => isset( $address['department'] ) ? $address['department'] : '',
			'country'    => 'SV',
		);
		// Sin correo del comprador, a propósito: es cliente de Urbby y Woo no le escribe.
		$order->set_address( array_merge( $fields, array( 'phone' => isset( $address['phone'] ) ? $address['phone'] : '' ) ), 'billing' );
		$order->set_address( $fields, 'shipping' );

		$map = Urbby_WC_Stock::variant_map();
		foreach ( (array) $f['items'] as $line ) {
			$item = new WC_Order_Item_Product();
			$woo  = ! empty( $line['variantId'] ) && isset( $map[ $line['variantId'] ] ) ? wc_get_product( $map[ $line['variantId'] ] ) : null;
			if ( $woo ) {
				$item->set_product( $woo );
			}
			$item->set_name( $line['title'] . ( ! empty( $line['variantLabel'] ) ? ' — ' . $line['variantLabel'] : '' ) );
			$item->set_quantity( (int) $line['quantity'] );
			// Precio de la compra, sin el descuento del cupón: las promociones de Urbby no tocan Woo.
			$item->set_subtotal( $line['subtotal'] );
			$item->set_total( $line['subtotal'] );
			if ( ! empty( $line['variantId'] ) ) {
				$item->add_meta_data( '_urbby_variant_id', $line['variantId'], true );
			}
			$order->add_item( $item );
			if ( ! $woo ) {
				$order->add_order_note( 'Urbby: «' . $line['title'] . '» no está vinculado a un producto de esta tienda.' );
			}
		}

		$order->set_payment_method( 'urbby' );
		$order->set_payment_method_title( 'Urbby' . ( 'cod' === ( isset( $f['paymentMethod'] ) ? $f['paymentMethod'] : '' ) ? ' (contra entrega)' : '' ) );
		$order->calculate_totals( false );
		$order->add_order_note(
			( $sandbox ? 'PRUEBA del Sandbox de Urbby. ' : '' ) . 'Pedido #' . $f['orderNumber'] . ' de Urbby. El cobro y el envío los maneja Urbby; el estado se actualiza solo.'
			. ( ! empty( $f['couponCode'] ) ? ' El comprador usó el cupón ' . $f['couponCode'] . ' en Urbby: no cambia esta orden.' : '' )
		);
		self::apply_status( $order, $f );
		$order->save();

		// La copia local se pone al día con el número de Urbby, no restando acá: si la sincronización
		// ya vio esta venta (un aviso que llegó tarde, la reconciliación), restar la contaría dos veces.
		if ( ! $sandbox ) {
			$products = array();
			foreach ( $order->get_items() as $item ) {
				$urbby_product = $item instanceof WC_Order_Item_Product ? get_post_meta( $item->get_product_id(), Urbby_WC_Catalog::META_PRODUCT, true ) : '';
				if ( $urbby_product ) {
					$products[ $urbby_product ] = true;
				}
			}
			Urbby_WC_Stock::refresh_products( array_keys( $products ) );
		}
	}

	/**
	 * @param WC_Order $order Orden de Woo.
	 * @param array    $f     Pedido de Urbby.
	 */
	private static function apply_status( WC_Order $order, array $f ) {
		$status = Urbby_WC_Mapper::woo_status( $f );
		if ( $order->get_status() !== $status ) {
			$order->set_status( $status, 'Urbby: ' . self::status_label( $f ) . '.' );
		}
	}

	/**
	 * @param array $f Pedido.
	 * @return string
	 */
	private static function status_label( array $f ) {
		$labels = array(
			'nuevo'             => 'pedido nuevo',
			'preparando'        => 'en preparación',
			'listo_recoleccion' => 'listo para que lo recojan',
			'recogido'          => 'recogido por el transportista',
			'cancelado'         => 'cancelado',
		);
		$label  = isset( $labels[ $f['status'] ] ) ? $labels[ $f['status'] ] : $f['status'];
		if ( ! empty( $f['shipment']['status'] ) ) {
			$label .= ' (envío: ' . $f['shipment']['status'] . ')';
		}
		return $label;
	}

	/**
	 * Lo que un aviso perdido no trajo (WordPress caído más de una hora, un aviso que Urbby no llegó a
	 * mandar): repasa los pedidos recientes y crea o actualiza lo que falte. Idempotente.
	 */
	public static function reconcile() {
		if ( ! Urbby_WC_Settings::connected() ) {
			return;
		}
		$cursor = null;
		for ( $page = 0; $page < self::RECONCILE_PAGES; $page++ ) {
			$res = Urbby_WC_Api::orders( $cursor );
			if ( ! $res->ok() ) {
				Urbby_WC_Log::error( 'No se pudieron leer los pedidos de Urbby.', array( 'status' => $res->status ) );
				return;
			}
			foreach ( (array) $res->body['data'] as $fulfillment ) {
				self::upsert( $fulfillment );
			}
			$cursor = isset( $res->body['nextCursor'] ) ? $res->body['nextCursor'] : null;
			if ( ! $cursor ) {
				return;
			}
		}
	}
}
