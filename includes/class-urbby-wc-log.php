<?php
/**
 * Registro en WooCommerce → Estado → Registros (fuente `urbby`). Nunca datos del comprador ni secretos.
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Envoltorio mínimo del logger de WooCommerce.
 */
final class Urbby_WC_Log {

	/**
	 * @param string $message Qué pasó.
	 * @param array  $context Ids y códigos, nada personal.
	 */
	public static function info( $message, array $context = array() ) {
		wc_get_logger()->info( $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ), array( 'source' => 'urbby' ) );
	}

	/**
	 * @param string $message Qué falló.
	 * @param array  $context Ids y códigos, nada personal.
	 */
	public static function error( $message, array $context = array() ) {
		wc_get_logger()->error( $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ), array( 'source' => 'urbby' ) );
	}
}
