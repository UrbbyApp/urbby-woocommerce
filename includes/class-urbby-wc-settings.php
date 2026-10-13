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
	 * Hay credencial y es este sitio.
	 *
	 * @return bool
	 */
	public static function connected() {
		$s = self::get();
		return '' !== $s['client_id'] && '' !== $s['client_secret'] && ! self::moved();
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
