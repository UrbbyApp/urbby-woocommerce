<?php
/**
 * «Conectar con Urbby»: autorizar en el portal y canjear el código con PKCE, y desconectar.
 *
 * El plugin es un cliente público: lo descarga cualquiera, así que no puede guardar un secreto de app.
 * Lo que prueba que quien canjea es quien empezó es el `code_verifier` de PKCE, que nunca sale de este
 * servidor (vive en un transient atado al usuario y al `state`, 15 minutos y un solo uso).
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Flujo de conexión de la pantalla WooCommerce → Urbby.
 */
final class Urbby_WC_Connect {

	/** Vida del verifier y el `state`: lo que tarda una persona en autorizar en el portal. */
	const TTL = 900;

	public static function hooks() {
		add_action( 'admin_post_urbby_wc_connect', array( __CLASS__, 'start' ) );
		add_action( 'admin_post_urbby_wc_disconnect', array( __CLASS__, 'disconnect_action' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_finish' ) );
	}

	/**
	 * A donde vuelve el portal: la pantalla del plugin. Es la misma, carácter por carácter, al autorizar
	 * y al canjear.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return add_query_arg( array( 'page' => Urbby_WC_Admin::PAGE ), admin_url( 'admin.php' ) );
	}

	/**
	 * El endpoint REST del plugin al que Urbby manda los avisos.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( 'urbby/v1/webhook' );
	}

	/**
	 * @return string Clave del transient: un intento de conexión por usuario.
	 */
	private static function key() {
		return 'urbby_wc_connect_' . get_current_user_id();
	}

	/**
	 * Botón «Conectar con Urbby»: prepara PKCE y manda a la persona al portal.
	 */
	public static function start() {
		if ( ! current_user_can( Urbby_WC_Admin::CAP ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'urbby-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'urbby_wc_connect' );

		$redirect = self::redirect_uri();
		$problem  = Urbby_WC_Mapper::connect_problem( $redirect, self::webhook_url() );
		if ( 'https' === $problem ) {
			Urbby_WC_Admin::back( __( 'Para conectar, tu sitio tiene que abrirse por https (Urbby manda los avisos de pedidos a esa dirección).', 'urbby-woocommerce' ), 'error' );
		}
		if ( 'host' === $problem ) {
			Urbby_WC_Admin::back( __( 'El administrador de WordPress y la dirección de la API REST están en dominios distintos. Urbby necesita que sean el mismo para conectar.', 'urbby-woocommerce' ), 'error' );
		}

		$verifier = Urbby_WC_Mapper::new_verifier();
		$state    = Urbby_WC_Mapper::base64url( random_bytes( 24 ) );
		set_transient(
			self::key(),
			array(
				'state'    => $state,
				'verifier' => $verifier,
				'redirect' => $redirect,
			),
			self::TTL
		);
		// El destino lo fija la constante del portal, no la persona: `wp_safe_redirect` rechazaría un host ajeno.
		wp_redirect( Urbby_WC_Mapper::authorize_url( Urbby_WC_Settings::portal_base(), $redirect, $state, Urbby_WC_Mapper::pkce_challenge( $verifier ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * La vuelta del portal: `?page=urbby-woocommerce&code=…&state=…` (o `&error=access_denied&state=…`).
	 * Corre en `admin_init`, antes de pintar nada, y termina siempre en un redirect a la pantalla limpia.
	 */
	public static function maybe_finish() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- El `state` atado al usuario hace de nonce: lo manda el portal, no un formulario nuestro.
		if ( ! isset( $_GET['page'], $_GET['state'] ) || Urbby_WC_Admin::PAGE !== $_GET['page'] || ! ( isset( $_GET['code'] ) || isset( $_GET['error'] ) ) ) {
			return;
		}
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		if ( ! current_user_can( Urbby_WC_Admin::CAP ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'urbby-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$saved = get_transient( self::key() );
		delete_transient( self::key() ); // Un solo uso: ni un reintento ni un enlace guardado lo reutilizan.
		if ( ! is_array( $saved ) || ! hash_equals( (string) $saved['state'], $state ) ) {
			Urbby_WC_Admin::back( __( 'La conexión venció o no la empezaste desde esta pantalla. Tocá «Conectar con Urbby» de nuevo.', 'urbby-woocommerce' ), 'error' );
		}
		if ( '' !== $error ) {
			Urbby_WC_Admin::back( __( 'No se conectó: cancelaste la autorización en Urbby.', 'urbby-woocommerce' ), 'info' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $code ) ) {
			Urbby_WC_Admin::back( __( 'Urbby devolvió un código que no es válido. Tocá «Conectar con Urbby» de nuevo.', 'urbby-woocommerce' ), 'error' );
		}

		$res = Urbby_WC_Api::exchange_code(
			array(
				'code'         => $code,
				'codeVerifier' => $saved['verifier'],
				'redirectUri'  => $saved['redirect'],
				'clientId'     => 'woocommerce',
				'webhookUrl'   => self::webhook_url(),
			)
		);
		if ( ! $res->ok() ) {
			Urbby_WC_Log::error(
				'No se pudo canjear el código de conexión.',
				array(
					'status' => $res->status,
					'code'   => $res->code(),
				)
			);
			Urbby_WC_Admin::back( self::exchange_error( $res ), 'error' );
		}
		self::save_connection( $res->body );
	}

	/**
	 * Guarda la credencial y el webhook (como si se hubieran pegado a mano) y manda el catálogo.
	 *
	 * @param array $body `IntegrationToken`.
	 */
	private static function save_connection( array $body ) {
		if ( empty( $body['clientId'] ) || empty( $body['secret'] ) ) {
			Urbby_WC_Admin::back( __( 'Urbby respondió sin la credencial. Tocá «Conectar con Urbby» de nuevo.', 'urbby-woocommerce' ), 'error' );
		}
		$webhook = isset( $body['webhook'] ) && is_array( $body['webhook'] ) ? $body['webhook'] : array();
		Urbby_WC_Settings::update(
			array(
				'client_id'      => (string) $body['clientId'],
				'client_secret'  => (string) $body['secret'],
				'webhook_secret' => isset( $webhook['secret'] ) ? (string) $webhook['secret'] : '',
				'webhook_id'     => isset( $webhook['id'] ) ? (string) $webhook['id'] : '',
				'connection'     => 'connect',
				'store_name'     => isset( $body['store']['name'] ) ? sanitize_text_field( $body['store']['name'] ) : '',
				'site'           => home_url(),
				'auth_failed'    => 0,
			)
		);
		delete_transient( 'urbby_wc_categories' );
		Urbby_WC_Plugin::ensure_schedules();

		if ( empty( $webhook['secret'] ) ) {
			Urbby_WC_Admin::back( __( 'Se conectó la credencial, pero Urbby no creó el webhook de pedidos. Creálo en el portal (Integraciones) y pegá su secreto en «Avanzado».', 'urbby-woocommerce' ), 'error' );
		}
		// El catálogo completo, igual que el botón «Enviar catálogo a Urbby»: avanza en segundo plano.
		$queued = Urbby_WC_Catalog::push_all();
		Urbby_WC_Admin::back(
			sprintf(
				/* translators: 1: nombre del comercio en Urbby, 2: cantidad de productos. */
				__( 'Conectado con Urbby (%1$s). Se encolaron %2$d productos para enviar: avanza en segundo plano. Falta elegir la categoría de Urbby de cada categoría de tu tienda.', 'urbby-woocommerce' ),
				Urbby_WC_Settings::get()['store_name'],
				$queued
			),
			'success'
		);
	}

	/**
	 * @param Urbby_WC_Response $res Respuesta del canje que falló.
	 * @return string
	 */
	private static function exchange_error( Urbby_WC_Response $res ) {
		$again = ' ' . __( 'Tocá «Conectar con Urbby» de nuevo.', 'urbby-woocommerce' );
		if ( 0 === $res->status ) {
			// Sin respuesta no sabemos si Urbby llegó a usar el código: casi seguro que sí, hay que repetir.
			return __( 'Urbby no respondió, y no sabemos si llegó a conectar.', 'urbby-woocommerce' ) . $again;
		}
		$known = array(
			'code_expired'            => __( 'El código de autorización venció (dura 5 minutos).', 'urbby-woocommerce' ),
			'code_used'               => __( 'Ese código de autorización ya se usó.', 'urbby-woocommerce' ),
			'invalid_grant'           => __( 'Urbby no aceptó el código de autorización.', 'urbby-woocommerce' ),
			'redirect_uri_mismatch'   => __( 'La dirección de regreso no es la misma con la que se autorizó.', 'urbby-woocommerce' ),
			'WEBHOOK_URL_NOT_ALLOWED' => __( 'Urbby no aceptó la dirección de avisos de este sitio: tiene que ser https, pública y del mismo dominio que el administrador.', 'urbby-woocommerce' ),
			'RATE_LIMITED'            => __( 'Demasiados intentos desde esta conexión. Esperá unos minutos.', 'urbby-woocommerce' ),
		);
		$why   = isset( $known[ $res->code() ] ) ? $known[ $res->code() ] : $res->message();
		return $why . ( 'RATE_LIMITED' === $res->code() ? '' : $again );
	}

	/**
	 * Botón «Desconectar».
	 */
	public static function disconnect_action() {
		if ( ! current_user_can( Urbby_WC_Admin::CAP ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'urbby-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'urbby_wc_disconnect' );
		if ( self::disconnect() ) {
			Urbby_WC_Admin::back( __( 'Desconectado de Urbby. Tus productos quedaron en borrador en Urbby.', 'urbby-woocommerce' ), 'success' );
		}
		Urbby_WC_Admin::back( __( 'No pudimos avisarle a Urbby que te desconectás, así que la conexión sigue como estaba. Probá de nuevo en un momento.', 'urbby-woocommerce' ), 'error' );
	}

	/**
	 * Revoca la credencial en Urbby (ahí los productos `woo-*` pasan a borrador y se borra el webhook) y,
	 * si Urbby ya no la conoce o la revocó, la olvida acá. Si Urbby no contesta se queda como estaba: así
	 * la credencial no queda viva en Urbby y olvidada acá, sin forma de revocarla desde el plugin.
	 * También lo llaman la desactivación y la desinstalación, con un timeout corto.
	 *
	 * @param int $timeout Segundos.
	 * @return bool Si quedó desconectado.
	 */
	public static function disconnect( $timeout = 8 ) {
		$s = Urbby_WC_Settings::get();
		if ( '' === $s['client_id'] ) {
			return true;
		}
		// Una copia del sitio (la dirección cambió) hereda la credencial de la original: no la revoca.
		if ( ! Urbby_WC_Settings::moved() ) {
			$res = Urbby_WC_Api::revoke_credential( $timeout );
			// 401: Urbby ya no la conoce (la revocaron desde el portal): no hay nada más que revocar.
			if ( 401 !== $res->status && ! ( $res->status >= 200 && $res->status < 300 ) ) {
				return false;
			}
		}
		Urbby_WC_Settings::forget_connection();
		Urbby_WC_Plugin::unschedule();
		return true;
	}
}
