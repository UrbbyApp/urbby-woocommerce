<?php
/**
 * Cliente HTTP de la API pública de Urbby.
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Las llamadas que usa el plugin. La credencial va por Basic Auth; el comercio lo decide Urbby a partir
 * de ella, nunca este lado.
 */
final class Urbby_WC_Api {

	/**
	 * @param string     $method  Verbo.
	 * @param string     $path    Ruta bajo `/v1`, con la barra inicial.
	 * @param mixed      $body    Cuerpo JSON (array u objeto). Null en un GET.
	 * @param int        $timeout Segundos.
	 * @return Urbby_WC_Response
	 */
	public static function request( $method, $path, $body = null, $timeout = 15 ) {
		if ( Urbby_WC_Settings::moved() ) {
			return new Urbby_WC_Response( 0, null, __( 'este sitio no es el que se conectó; conectalo de nuevo', 'urbby-woocommerce' ) );
		}
		$s    = Urbby_WC_Settings::get();
		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Basic Auth.
				'Authorization' => 'Basic ' . base64_encode( $s['client_id'] . ':' . $s['client_secret'] ),
				'Accept'        => 'application/json',
				'User-Agent'    => 'urbby-woocommerce/' . URBBY_WC_VERSION,
			),
		);
		// El API exige JSON en todo POST/PATCH, aunque no lleve datos (si no, 415).
		if ( null === $body && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$body = new stdClass();
		}
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = self::to_response( wp_remote_request( Urbby_WC_Settings::api_base() . $path, $args ) );
		// Un 401 es la credencial revocada (p. ej. «Desconectar» en el portal): la pantalla del plugin lo dice.
		if ( 401 === $res->status && '' !== $s['client_id'] && ! $s['auth_failed'] ) {
			Urbby_WC_Settings::update( array( 'auth_failed' => time() ) );
		} elseif ( $res->status >= 200 && $res->status < 300 && $s['auth_failed'] ) {
			Urbby_WC_Settings::update( array( 'auth_failed' => 0 ) );
		}
		return $res;
	}

	/**
	 * @param array|WP_Error $res Lo que devolvió `wp_remote_request`.
	 * @return Urbby_WC_Response
	 */
	private static function to_response( $res ) {
		if ( is_wp_error( $res ) ) {
			return new Urbby_WC_Response( 0, null, $res->get_error_message() );
		}
		return new Urbby_WC_Response( wp_remote_retrieve_response_code( $res ), json_decode( wp_remote_retrieve_body( $res ), true ) );
	}

	/**
	 * Canjea el código de «Conectar con Urbby» por la credencial y el webhook. Sin Basic: el plugin es un
	 * cliente público (no guarda un secreto de app) y lo que prueba quién canjea es el `codeVerifier` de PKCE.
	 * Aparte de `request()`: todavía no hay credencial, y este sitio puede figurar como «movido».
	 *
	 * @param array $body `code`, `codeVerifier`, `redirectUri`, `clientId`, `webhookUrl`.
	 * @return Urbby_WC_Response
	 */
	public static function exchange_code( array $body ) {
		return self::to_response(
			wp_remote_post(
				Urbby_WC_Settings::api_base() . '/public/integrations/token',
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'headers'     => array(
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
						'User-Agent'   => 'urbby-woocommerce/' . URBBY_WC_VERSION,
					),
					'body'        => wp_json_encode( $body ),
				)
			)
		);
	}

	/**
	 * La credencial se revoca a sí misma (desconectar). En la conexión «Conectar con Urbby» Urbby además
	 * pasa a borrador los productos `woo-*` y borra el webhook.
	 *
	 * @param int $timeout Segundos: se llama al desactivar o desinstalar, que no puede quedar esperando.
	 * @return Urbby_WC_Response
	 */
	public static function revoke_credential( $timeout = 8 ) {
		return self::request( 'DELETE', '/public/integrations/credential', null, $timeout );
	}

	/** @return Urbby_WC_Response */
	public static function categories() {
		return self::request( 'GET', '/catalog/categories' );
	}

	/**
	 * @param string|null $cursor Página.
	 * @return Urbby_WC_Response
	 */
	public static function products( $cursor = null ) {
		return self::request( 'GET', '/public/catalog/products?limit=100' . ( $cursor ? '&cursor=' . rawurlencode( $cursor ) : '' ) );
	}

	/**
	 * @param string $product_id Producto de Urbby.
	 * @return Urbby_WC_Response
	 */
	public static function product( $product_id ) {
		return self::request( 'GET', '/public/catalog/products/' . rawurlencode( $product_id ) );
	}

	/**
	 * @param array $items Filas de `BulkProductItem`.
	 * @return Urbby_WC_Response
	 */
	public static function bulk_products( array $items ) {
		return self::request( 'POST', '/public/catalog/products/bulk', array( 'items' => $items ), 60 );
	}

	/**
	 * @param array $items Filas de `BulkStockItem`.
	 * @return Urbby_WC_Response
	 */
	public static function bulk_stock( array $items ) {
		return self::request( 'PATCH', '/public/inventory/bulk', array( 'items' => $items ), 30 );
	}

	/**
	 * Aparta stock. Timeout corto: lo espera un cliente en el checkout.
	 *
	 * @param string $reference    Llave de idempotencia.
	 * @param array  $lines        `[[ 'variantId', 'quantity' ], …]`.
	 * @param int    $hold_minutes Vencimiento.
	 * @return Urbby_WC_Response
	 */
	public static function reserve( $reference, array $lines, $hold_minutes ) {
		return self::request(
			'POST',
			'/public/stock-reservations',
			array(
				'reference'   => $reference,
				'holdMinutes' => (int) $hold_minutes,
				'lines'       => $lines,
			),
			8
		);
	}

	/**
	 * @param string $reservation_id Reserva.
	 * @return Urbby_WC_Response
	 */
	public static function confirm( $reservation_id ) {
		return self::request( 'POST', '/public/stock-reservations/' . rawurlencode( $reservation_id ) . '/confirm', null, 10 );
	}

	/**
	 * @param string $reservation_id Reserva.
	 * @return Urbby_WC_Response
	 */
	public static function release( $reservation_id ) {
		return self::request( 'POST', '/public/stock-reservations/' . rawurlencode( $reservation_id ) . '/release', null, 10 );
	}

	/**
	 * @param string|null $cursor Página.
	 * @return Urbby_WC_Response
	 */
	public static function orders( $cursor = null ) {
		return self::request( 'GET', '/public/orders?limit=50' . ( $cursor ? '&cursor=' . rawurlencode( $cursor ) : '' ) );
	}

	/**
	 * @param string $fulfillment_id Pedido.
	 * @return Urbby_WC_Response
	 */
	public static function order( $fulfillment_id ) {
		return self::request( 'GET', '/public/orders/' . rawurlencode( $fulfillment_id ) );
	}

	/**
	 * Cancela en Urbby el pedido que se canceló en la tienda. Idempotente: si ya estaba cancelado, 200.
	 *
	 * @param string $fulfillment_id Pedido.
	 * @return Urbby_WC_Response
	 */
	public static function cancel_order( $fulfillment_id ) {
		return self::request( 'POST', '/public/orders/' . rawurlencode( $fulfillment_id ) . '/cancel', null, 15 );
	}
}
