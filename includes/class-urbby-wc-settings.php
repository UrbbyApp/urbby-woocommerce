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

	/** El API de producción. `custom` usa `api_base` tal cual (pruebas). */
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
				'environment'      => 'production',
				'api_base'         => '',
				'client_id'        => '',
				'client_secret'    => '',
				'webhook_secret'   => '',
				'hold_minutes'     => 60,
				'default_category' => '',
				'category_map'     => array(),
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
	 * La URL base del API, sin barra final.
	 *
	 * @return string
	 */
	public static function api_base() {
		$s = self::get();
		if ( 'custom' === $s['environment'] ) {
			return untrailingslashit( (string) $s['api_base'] );
		}
		return self::PRODUCTION;
	}

	/**
	 * Hay credencial para hablar con Urbby.
	 *
	 * @return bool
	 */
	public static function connected() {
		$s = self::get();
		return '' !== $s['client_id'] && '' !== $s['client_secret'];
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
