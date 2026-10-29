<?php
/**
 * Ajustes de la conexión, guardados en `wp_options` (sin autoload: hay secretos).
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lectura y escritura de los ajustes.
 */
final class Urbby_WC_Settings {

	const OPTION = 'urbby_wc_settings';

	/** El API de Urbby. Otro (pruebas) solo con la constante `URBBY_WC_API_BASE` en `wp-config.php`. */
	const PRODUCTION = 'https://api.urbby.app/v1';

	/** El portal de comercios, donde se autoriza la conexión. Otro (pruebas) solo con `URBBY_WC_PORTAL_BASE` en `wp-config.php`. */
	const PORTAL = 'https://comercios.urbby.app';

	/**
	 * Ajustes con sus valores por defecto.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'client_id'        => '',
				'client_secret'    => '',
				'webhook_secret'   => '',
				'hold_minutes'     => 60,
				'default_category' => '',
				'category_map'     => array(),
				'site'             => '',
				// `connect` si la conexión salió de «Conectar con Urbby»; '' si se pegó a mano (v0.1.0).
				'connection'       => '',
				'webhook_id'       => '',
				'store_name'       => '',
				// Marca de que Urbby contestó 401: la credencial se revocó (p. ej. desde el portal).
				'auth_failed'      => 0,
			)
		);
	}

	/**
	 * Guarda una parte de los ajustes.
	 *
	 * @param array $patch Campos a cambiar.
	 */
	public static function update( array $patch ) {
		update_option( self::OPTION, array_merge( self::get(), $patch ), false );
	}

	/**
	 * La URL base del API, sin barra final. Se cambia solo desde `wp-config.php`: desde la pantalla,
	 * cualquiera con permiso de gestionar la tienda podía mandar la credencial a otro servidor.
	 *
	 * @return string
	 */
	public static function api_base() {
		return defined( 'URBBY_WC_API_BASE' ) ? untrailingslashit( (string) URBBY_WC_API_BASE ) : self::PRODUCTION;
	}

	/**
	 * La URL base del portal de comercios, sin barra final. Como el API, solo se cambia desde `wp-config.php`:
	 * es a donde se manda a la persona a autorizar.
	 *
	 * @return string
	 */
	public static function portal_base() {
		return defined( 'URBBY_WC_PORTAL_BASE' ) ? untrailingslashit( (string) URBBY_WC_PORTAL_BASE ) : self::PORTAL;
	}

	/**
	 * Olvida la credencial y el webhook de la conexión (los ajustes de categorías y reservas se quedan).
	 */
	public static function forget_connection() {
		self::update(
			array(
				'client_id'      => '',
				'client_secret'  => '',
				'webhook_secret' => '',
				'webhook_id'     => '',
				'connection'     => '',
				'store_name'     => '',
				'site'           => '',
				'auth_failed'    => 0,
			)
		);
	}

	/**
	 * Este sitio es otro que el que se conectó: una copia (staging clonado, backup restaurado en otra
	 * dirección) que heredó la credencial. Mientras no se vuelva a guardar la conexión, no habla con
	 * Urbby: si no, vendería y movería el stock real desde la copia.
	 *
	 * @return bool
	 */
	public static function moved() {
		$s = self::get();
		return '' !== $s['site'] && untrailingslashit( strtolower( $s['site'] ) ) !== untrailingslashit( strtolower( home_url() ) );
	}

	/**
	 * Hay credencial, es este sitio y Urbby no la rechazó (401).
	 *
	 * @return bool
	 */
	public static function connected() {
		$s = self::get();
		return '' !== $s['client_id'] && '' !== $s['client_secret'] && ! self::moved() && ! $s['auth_failed'];
	}

	/**
	 * Las ventas de la tienda pasan por el stock de Urbby. Sin credencial, o con una que Urbby revocó (se
	 * desconectó desde el portal), la tienda vende con su propio stock: si no, cada checkout de un
	 * producto vinculado fallaría. A diferencia de `connected()`, una copia del sitio (`moved`) sigue
	 * pasando por Urbby y falla: no vende con el stock de otro.
	 *
	 * @return bool
	 */
	public static function sells_via_urbby() {
		$s = self::get();
		return '' !== $s['client_id'] && ! $s['auth_failed'];
	}

	/**
	 * La categoría de Urbby para un producto: la primera de sus categorías de Woo que esté mapeada, o la
	 * de por defecto.
	 *
	 * @param int[] $woo_category_ids Categorías de Woo del producto.
	 * @return string Id de categoría de Urbby, o '' si no hay.
	 */
	public static function urbby_category_for( array $woo_category_ids ) {
		$s = self::get();
		foreach ( $woo_category_ids as $id ) {
			if ( ! empty( $s['category_map'][ $id ] ) ) {
				return (string) $s['category_map'][ $id ];
			}
		}
		return (string) $s['default_category'];
	}
}
