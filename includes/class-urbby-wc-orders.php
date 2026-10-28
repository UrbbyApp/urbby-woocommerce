<?php
/**
 * Pedidos de Urbby → órdenes de Woo, y su estado.
 *
 * La orden de Woo es un registro para que el comercio vea y despache todo desde un lugar: no cobra
 * (el cobro y la liquidación viven en Urbby), no descuenta stock (ya lo descontó Urbby al vender) y
 * va al precio de la compra, sin los descuentos de Urbby: las promociones de Urbby no tocan Woo.
 * Tampoco trae datos del comprador (nombre, teléfono, dirección): Urbby los maneja y coordina la entrega.
 * Lo único que va a la inversa es la cancelación: cancelarla en la tienda la cancela en Urbby.
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

	/** Cancelar en la tienda → cancelar en Urbby. `QUEUED` mientras se avisa; `FROM` es el estado al que vuelve si Urbby no puede. */
	const META_CANCEL_STATE = '_urbby_cancel_state';
	const META_CANCEL_FROM  = '_urbby_cancel_from';
	const ACTION_CANCEL     = 'urbby_wc_cancel_order';

	/** Intentos de avisar la cancelación (con espera creciente) antes de devolver la orden a su estado. */
	const CANCEL_ATTEMPTS = 6;

	/**
	 * Verdadero mientras ESTE plugin cambia la orden por lo que dijo Urbby (aviso, reconciliación) o la
	 * devuelve a su estado: esos cambios no son de una persona y no se mandan de vuelta a Urbby.
	 *
	 * @var bool
	 */
	private static $applying = false;

	/** Cuántas páginas de 50 mira la reconciliación: los pedidos de los últimos días. */
	const RECONCILE_PAGES = 4;

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( self::ACTION_RECONCILE, array( __CLASS__, 'reconcile' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( self::ACTION_CANCEL, array( __CLASS__, 'cancel_in_urbby' ), 10, 2 );
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
		// Candado de MySQL por pedido: dos avisos del mismo pedido a la vez (un reintento de Urbby y la
		// reconciliación) no crean dos órdenes. `add_option` no sirve: es un INSERT … ON DUPLICATE KEY.
		global $wpdb;
		$lock = 'urbby_wc_' . md5( $f['id'] );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return 'busy';
		}
		self::$applying = true;
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
			self::$applying = false;
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
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
				// Con la papelera: una orden de Urbby que el comercio tiró no se vuelve a crear con el próximo aviso.
				'status'     => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
			)
		);
		return $orders ? $orders[0] : null;
	}

	/**
	 * @param array $f       Pedido.
	 * @param bool  $sandbox Compra de prueba.
	 */
	private static function create( array $f, $sandbox ) {
		// Se arma entera y se guarda UNA vez: `wc_create_order` la persistía vacía, y una falla en el
		// medio dejaba una orden huérfana por cada reintento.
		$order = new WC_Order();
		$order->set_created_via( 'urbby' );
		$order->update_meta_data( self::META_FULFILLMENT, $f['id'] );
		$order->update_meta_data( self::META_NUMBER, $f['orderNumber'] );
		$order->update_meta_data( self::META_VERSION, Urbby_WC_Mapper::version( $f ) );

		// Sin datos del comprador, a propósito: es cliente de Urbby, que coordina la entrega. Solo una
		// referencia para reconocer la orden en la lista de Woo.
		$order->set_billing_first_name( 'Urbby' );
		$order->set_billing_last_name( '#' . $f['orderNumber'] );

		$map   = Urbby_WC_Stock::variant_map();
		$notes = array();
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
				/* translators: %s: nombre del producto en Urbby. */
				$notes[] = sprintf( __( 'Urbby: «%s» no está vinculado a un producto de esta tienda.', 'urbby-woocommerce' ), $line['title'] );
			}
		}

		$order->set_payment_method( 'urbby' );
		$order->set_payment_method_title( 'cod' === ( isset( $f['paymentMethod'] ) ? $f['paymentMethod'] : '' ) ? __( 'Contra entrega (Urbby)', 'urbby-woocommerce' ) : 'Urbby' );
		$order->calculate_totals( false );
		array_unshift(
			$notes,
			( $sandbox ? __( 'PRUEBA del Sandbox de Urbby.', 'urbby-woocommerce' ) . ' ' : '' )
			/* translators: %s: número de pedido de Urbby. */
			. sprintf( __( 'Pedido #%s de Urbby. El cobro, la entrega y los datos del comprador los maneja Urbby; el estado se actualiza solo.', 'urbby-woocommerce' ), $f['orderNumber'] )
			. ( ! empty( $f['couponCode'] )
				/* translators: %s: código del cupón. */
				? ' ' . sprintf( __( 'El comprador usó el cupón %s en Urbby: no cambia esta orden.', 'urbby-woocommerce' ), $f['couponCode'] )
				: '' )
		);
		self::apply_status( $order, $f );
		$order->save();
		foreach ( $notes as $note ) {
			$order->add_order_note( $note );
		}

		// La copia local se pone al día con el número de Urbby, no restando acá: si la sincronización
		// ya vio esta venta (un aviso que llegó tarde, la reconciliación), restar la contaría dos veces.
		// En segundo plano: el aviso tiene que contestar rápido.
		if ( ! $sandbox ) {
			$products = array();
			foreach ( $order->get_items() as $item ) {
				$urbby_product = $item instanceof WC_Order_Item_Product ? get_post_meta( $item->get_product_id(), Urbby_WC_Catalog::META_PRODUCT, true ) : '';
				if ( $urbby_product ) {
					$products[ $urbby_product ] = true;
				}
			}
			if ( $products ) {
				as_enqueue_async_action( Urbby_WC_Stock::ACTION_REFRESH, array( array_keys( $products ) ), 'urbby' );
			}
		}
	}

	/**
	 * @param WC_Order $order Orden de Woo.
	 * @param array    $f     Pedido de Urbby.
	 */
	private static function apply_status( WC_Order $order, array $f ) {
		$status = Urbby_WC_Mapper::woo_status( $f );
		if ( $order->get_status() !== $status ) {
			/* translators: %s: estado del pedido en Urbby. */
			$order->set_status( $status, sprintf( __( 'Urbby: %s.', 'urbby-woocommerce' ), self::status_label( $f ) ) );
		}
		// Entregado: el contra entrega se cobró al entregar. Woo no marca «pagada» una orden que nunca pasó por una pasarela.
		if ( 'completed' === $status && ! $order->get_date_paid() ) {
			$order->set_date_paid( time() );
		}
	}

	/**
	 * @param string $status Estado de un pedido de Urbby.
	 * @return string
	 */
	public static function status_text( $status ) {
		$labels = array(
			'nuevo'             => __( 'pedido nuevo', 'urbby-woocommerce' ),
			'preparando'        => __( 'en preparación', 'urbby-woocommerce' ),
			'listo_recoleccion' => __( 'listo para que lo recojan', 'urbby-woocommerce' ),
			'recogido'          => __( 'recogido por el transportista', 'urbby-woocommerce' ),
			'cancelado'         => __( 'cancelado', 'urbby-woocommerce' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * @param array $f Pedido.
	 * @return string
	 */
	private static function status_label( array $f ) {
		$label = self::status_text( $f['status'] );
		if ( ! empty( $f['shipment']['status'] ) ) {
			/* translators: 1: estado del pedido, 2: estado del envío. */
			$label = sprintf( __( '%1$s (envío: %2$s)', 'urbby-woocommerce' ), $label, $f['shipment']['status'] );
		}
		return $label;
	}

	// ─────────────────────────────────────────────────────────── cancelar en la tienda = cancelar en Urbby

	/**
	 * Una persona canceló en Woo una orden que vino de Urbby: se avisa a Urbby en segundo plano. Lo que
	 * cambia este plugin por lo que dijo Urbby (`$applying`) no entra: ahí no hay nadie que avisar y
	 * volvería a Urbby lo que Urbby acaba de decir.
	 *
	 * @param int      $order_id Orden.
	 * @param string   $from     Estado anterior (sin `wc-`).
	 * @param string   $to       Estado nuevo.
	 * @param WC_Order $order    Orden.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( self::$applying || 'cancelled' !== $to || ! $order instanceof WC_Order || ! $order->get_meta( self::META_FULFILLMENT ) || 'queued' === $order->get_meta( self::META_CANCEL_STATE ) ) {
			return;
		}
		$order->update_meta_data( self::META_CANCEL_STATE, 'queued' );
		$order->update_meta_data( self::META_CANCEL_FROM, (string) $from );
		$order->save_meta_data();
		Urbby_WC_Stock::later( self::ACTION_CANCEL, array( (int) $order_id, 0 ), 1 );
	}

	/**
	 * `POST /public/orders/{id}/cancel`. Cancelada (o ya lo estaba): nota. Sin respuesta o error de
	 * Urbby: se reintenta con espera creciente. Urbby no puede (ya está lista para recoger, sin
	 * permiso…): la orden vuelve a su estado anterior con el motivo. Así Woo no se queda «cancelada»
	 * mientras Urbby sigue adelante con el envío. Idempotente: Urbby contesta 200 si ya estaba cancelada.
	 *
	 * @param int $order_id Orden de Woo.
	 * @param int $attempt  Intento (0 el primero).
	 */
	public static function cancel_in_urbby( $order_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'queued' !== $order->get_meta( self::META_CANCEL_STATE ) ) {
			return;
		}
		if ( 'cancelled' !== $order->get_status() ) {
			// La reabrieron antes de que corriera: no hay nada que cancelar.
			$order->delete_meta_data( self::META_CANCEL_STATE );
			$order->delete_meta_data( self::META_CANCEL_FROM );
			$order->save_meta_data();
			return;
		}
		if ( ! Urbby_WC_Settings::connected() ) {
			self::restore( $order, __( 'este sitio no está conectado con Urbby.', 'urbby-woocommerce' ) );
			return;
		}
		$res = Urbby_WC_Api::cancel_order( (string) $order->get_meta( self::META_FULFILLMENT ) );
		$out = Urbby_WC_Mapper::cancel_outcome( $res );
		if ( 'done' === $out ) {
			$order->delete_meta_data( self::META_CANCEL_STATE );
			$order->delete_meta_data( self::META_CANCEL_FROM );
			$order->save_meta_data();
			$order->add_order_note( __( 'Urbby: el pedido también quedó cancelado en Urbby.', 'urbby-woocommerce' ) );
			return;
		}
		Urbby_WC_Log::error(
			'No se pudo cancelar el pedido en Urbby.',
			array(
				'order'   => (int) $order_id,
				'attempt' => (int) $attempt,
				'status'  => $res->status,
				'code'    => $res->code(),
			)
		);
		if ( 'retry' === $out && (int) $attempt + 1 < self::CANCEL_ATTEMPTS ) {
			Urbby_WC_Stock::later( self::ACTION_CANCEL, array( (int) $order_id, (int) $attempt + 1 ), Urbby_WC_Stock::backoff( (int) $attempt + 1 ) );
			return;
		}
		self::restore( $order, 'retry' === $out ? __( 'Urbby no respondió después de varios intentos.', 'urbby-woocommerce' ) : self::cancel_refusal( $res ) );
	}

	/**
	 * @param Urbby_WC_Response $res Respuesta de Urbby que no es un éxito ni se reintenta.
	 * @return string El motivo, para la nota de la orden.
	 */
	private static function cancel_refusal( Urbby_WC_Response $res ) {
		if ( 'FULFILLMENT_INVALID_TRANSITION' === $res->code() ) {
			return sprintf(
				/* translators: %s: estado actual del pedido en Urbby. */
				__( 'el pedido ya está «%s» en Urbby y desde la tienda no se puede cancelar. Si hay que cancelarlo, hablalo con Urbby.', 'urbby-woocommerce' ),
				self::status_text( isset( $res->body['details']['status'] ) ? (string) $res->body['details']['status'] : '' )
			);
		}
		if ( 404 === $res->status ) {
			return __( 'Urbby no encontró este pedido.', 'urbby-woocommerce' );
		}
		if ( 401 === $res->status || 403 === $res->status ) {
			return __( 'la credencial no tiene permiso para cancelar pedidos (orders:write). Tocá «Conectar con Urbby» en WooCommerce → Urbby para crear una con todos los permisos.', 'urbby-woocommerce' );
		}
		return wp_strip_all_tags( $res->message() );
	}

	/**
	 * Devuelve la orden al estado que tenía antes de que la cancelaran y deja el motivo en una nota.
	 *
	 * @param WC_Order $order  Orden.
	 * @param string   $reason Por qué Urbby no la canceló.
	 */
	private static function restore( WC_Order $order, $reason ) {
		$previous = (string) $order->get_meta( self::META_CANCEL_FROM );
		if ( '' === $previous || ! isset( wc_get_order_statuses()[ 'wc-' . $previous ] ) ) {
			$previous = 'processing';
		}
		$order->delete_meta_data( self::META_CANCEL_STATE );
		$order->delete_meta_data( self::META_CANCEL_FROM );
		self::$applying = true;
		try {
			$order->update_status(
				$previous,
				sprintf(
					/* translators: 1: motivo, 2: estado al que vuelve la orden. */
					__( 'Urbby no canceló este pedido: %1$s. Se devolvió la orden a «%2$s».', 'urbby-woocommerce' ),
					rtrim( $reason, '. ' ),
					wc_get_order_status_name( $previous )
				)
			);
		} finally {
			self::$applying = false;
		}
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
