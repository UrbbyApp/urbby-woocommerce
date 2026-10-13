<?php
/**
 * Reglas puras: sin WordPress ni WooCommerce adentro, para poder probarlas solas.
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || defined( 'URBBY_WC_TESTS' ) || exit;

/**
 * Traducciones entre el vocabulario de Urbby y el de WooCommerce.
 */
final class Urbby_WC_Mapper {

	/** Prefijo del externalId con el que Urbby reconoce un producto de esta tienda. */
	const EXTERNAL_PREFIX = 'woo-';

	/**
	 * El estado de la orden en Woo para un pedido de Urbby. Manda Urbby: lo que pase en Woo no vuelve.
	 *
	 * @param array $fulfillment El pedido (`MerchantFulfillment`).
	 * @return string Estado de Woo sin el prefijo `wc-`.
	 */
	public static function woo_status( array $fulfillment ) {
		$status   = isset( $fulfillment['status'] ) ? $fulfillment['status'] : 'nuevo';
		$shipment = isset( $fulfillment['shipment']['status'] ) ? $fulfillment['shipment']['status'] : null;

		if ( 'cancelado' === $status ) {
			return 'cancelled';
		}
		if ( 'delivered' === $shipment ) {
			return 'completed';
		}
		if ( 'delivery_failed' === $shipment || 'returned' === $shipment ) {
			return 'on-hold';
		}
		if ( 'recogido' === $status || in_array( $shipment, array( 'picked_up', 'in_warehouse', 'out_for_delivery' ), true ) ) {
			return 'urbby-en-camino';
		}
		if ( 'listo_recoleccion' === $status ) {
			return 'urbby-listo';
		}
		if ( 'preparando' === $status ) {
			return 'urbby-preparando';
		}
		return 'processing';
	}

	/**
	 * Llave de orden de los avisos: el más nuevo entre el pedido y su envío. Un aviso con una llave
	 * menor o igual a la guardada es viejo (llegó tarde por un reintento) y no se aplica.
	 *
	 * @param array $fulfillment El pedido.
	 * @return string Fecha ISO 8601 (comparable como texto: siempre en UTC con la misma forma).
	 */
	public static function version( array $fulfillment ) {
		$a = isset( $fulfillment['updatedAt'] ) ? (string) $fulfillment['updatedAt'] : '';
		$b = isset( $fulfillment['shipment']['updatedAt'] ) ? (string) $fulfillment['shipment']['updatedAt'] : '';
		return strcmp( $a, $b ) >= 0 ? $a : $b;
	}

	/**
	 * La firma de un aviso de Urbby: HMAC-SHA256 hex del cuerpo crudo.
	 *
	 * @param string $body      Cuerpo crudo, tal como llegó.
	 * @param string $signature Cabecera `X-Urbby-Signature`.
	 * @param string $secret    Secreto del webhook.
	 * @return bool
	 */
	public static function verify_signature( $body, $signature, $secret ) {
		if ( '' === (string) $secret || '' === (string) $signature ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', (string) $body, (string) $secret ), strtolower( trim( (string) $signature ) ) );
	}

	/**
	 * La `reference` de la reserva de una orden de Woo. Cambia si cambian las líneas (el cliente
	 * volvió al carrito): la misma orden con otras líneas es otra reserva.
	 *
	 * @param int   $order_id Orden de Woo.
	 * @param array $lines    `[[ 'variantId' => string, 'quantity' => int ], …]`.
	 * @return string
	 */
	public static function reservation_reference( $order_id, array $lines ) {
		$keys = array();
		foreach ( $lines as $line ) {
			$keys[] = $line['variantId'] . ':' . (int) $line['quantity'];
		}
		sort( $keys );
		return 'woo-order-' . (int) $order_id . '-' . substr( sha1( implode( ',', $keys ) ), 0, 10 );
	}

	/**
	 * Suma la cantidad de las líneas repetidas de una misma variante.
	 *
	 * @param array $lines `[[ 'variantId' => string, 'quantity' => int ], …]`.
	 * @return array
	 */
	public static function merge_lines( array $lines ) {
		$by = array();
		foreach ( $lines as $line ) {
			$id        = $line['variantId'];
			$by[ $id ] = array(
				'variantId' => $id,
				'quantity'  => ( isset( $by[ $id ] ) ? $by[ $id ]['quantity'] : 0 ) + (int) $line['quantity'],
			);
		}
		return array_values( $by );
	}

	/**
	 * Un monto de Woo («12.5», «12,50», «12.499») a lo que acepta Urbby («12.50»), sin pasar por float.
	 *
	 * @param mixed $value Monto.
	 * @return string|null Null si no es un monto positivo.
	 */
	public static function money( $value ) {
		$text = str_replace( ',', '.', trim( (string) $value ) );
		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?$/', $text, $m ) ) {
			return null;
		}
		$frac  = str_pad( isset( $m[2] ) ? $m[2] : '', 3, '0' );
		$cents = (int) $m[1] * 100 + (int) substr( $frac, 0, 2 ) + ( (int) $frac[2] >= 5 ? 1 : 0 );
		if ( $cents <= 0 ) {
			return null;
		}
		return intdiv( $cents, 100 ) . '.' . str_pad( (string) ( $cents % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * La fila de `PATCH /public/inventory/bulk` para un cambio relativo de stock hecho en Woo.
	 *
	 * @param string $variant_id Variante de Urbby.
	 * @param int    $delta      Positivo suma, negativo resta.
	 * @return array
	 */
	public static function stock_delta_row( $variant_id, $delta ) {
		return array(
			'variantId' => $variant_id,
			'mode'      => $delta >= 0 ? 'delta' : 'sale',
			'amount'    => abs( (int) $delta ),
		);
	}

	/**
	 * «Ana María López» → [ 'Ana María', 'López' ]. Urbby guarda un solo nombre.
	 *
	 * @param string $full Nombre completo.
	 * @return array{0:string,1:string}
	 */
	public static function split_name( $full ) {
		$parts = preg_split( '/\s+/', trim( (string) $full ) );
		if ( count( $parts ) < 2 ) {
			return array( (string) $parts[0], '' );
		}
		$last = array_pop( $parts );
		return array( implode( ' ', $parts ), $last );
	}

	/**
	 * Texto plano para Urbby: sin HTML ni espacios repetidos, cortado al máximo.
	 *
	 * @param string $html Texto de Woo.
	 * @param int    $max  Máximo de caracteres.
	 * @return string
	 */
	public static function plain_text( $html, $max ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Clase pura, sin WordPress (se prueba sola).
		$text = html_entity_decode( strip_tags( str_replace( array( '<br>', '<br/>', '<br />', '</p>' ), "\n", (string) $html ) ), ENT_QUOTES, 'UTF-8' );
		$text = trim( preg_replace( '/[ \t]+/', ' ', preg_replace( '/\n{3,}/', "\n\n", $text ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * La llave de una combinación de opciones, igual de los dos lados (sin mayúsculas ni espacios).
	 *
	 * @param array $values Valores de las opciones en orden.
	 * @return string
	 */
	public static function option_key( array $values ) {
		$norm = array();
		foreach ( array_slice( array_pad( $values, 3, '' ), 0, 3 ) as $v ) {
			$v      = trim( (string) $v );
			$norm[] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v ) : strtolower( $v );
		}
		return implode( '|', $norm );
	}
}
