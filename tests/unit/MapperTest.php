<?php
/**
 * Reglas puras del plugin.
 *
 * @package UrbbyWooCommerce
 */

use PHPUnit\Framework\TestCase;

final class MapperTest extends TestCase {

	private static function pedido( $status, $shipment = null ) {
		return array(
			'status'    => $status,
			'updatedAt' => '2026-10-13T10:00:00.000Z',
			'shipment'  => null === $shipment ? null : array(
				'status'    => $shipment,
				'updatedAt' => '2026-10-13T11:00:00.000Z',
			),
		);
	}

	public function test_el_estado_de_woo_sigue_al_de_urbby() {
		$casos = array(
			array( 'nuevo', null, 'processing' ),
			array( 'preparando', null, 'urbby-preparando' ),
			array( 'preparando', 'created', 'urbby-preparando' ),
			array( 'listo_recoleccion', 'created', 'urbby-listo' ),
			array( 'recogido', 'picked_up', 'urbby-en-camino' ),
			array( 'recogido', 'out_for_delivery', 'urbby-en-camino' ),
			array( 'recogido', 'delivered', 'completed' ),
			array( 'recogido', 'delivery_failed', 'on-hold' ),
			array( 'recogido', 'returned', 'on-hold' ),
			// Cancelado gana aunque el envío diga otra cosa.
			array( 'cancelado', 'cancelled', 'cancelled' ),
			array( 'cancelado', null, 'cancelled' ),
			// Entrega propia (sin envío) marcada recogida por el comercio.
			array( 'recogido', null, 'urbby-en-camino' ),
		);
		foreach ( $casos as $c ) {
			$this->assertSame( $c[2], Urbby_WC_Mapper::woo_status( self::pedido( $c[0], $c[1] ) ), $c[0] . '/' . var_export( $c[1], true ) );
		}
	}

	public function test_la_version_es_la_mas_nueva_entre_pedido_y_envio() {
		$this->assertSame( '2026-10-13T11:00:00.000Z', Urbby_WC_Mapper::version( self::pedido( 'recogido', 'picked_up' ) ) );
		$this->assertSame( '2026-10-13T10:00:00.000Z', Urbby_WC_Mapper::version( self::pedido( 'nuevo' ) ) );
		$this->assertLessThan( 0, strcmp( Urbby_WC_Mapper::version( self::pedido( 'nuevo' ) ), Urbby_WC_Mapper::version( self::pedido( 'recogido', 'picked_up' ) ) ) );
	}

	public function test_la_firma_es_hmac_sha256_del_cuerpo_crudo() {
		$body = '{"event":"order_created","eventId":"evt_1"}';
		$sig  = hash_hmac( 'sha256', $body, 'whsec' );
		$this->assertTrue( Urbby_WC_Mapper::verify_signature( $body, $sig, 'whsec' ) );
		$this->assertTrue( Urbby_WC_Mapper::verify_signature( $body, strtoupper( $sig ), 'whsec' ) );
		$this->assertFalse( Urbby_WC_Mapper::verify_signature( $body . ' ', $sig, 'whsec' ) );
		$this->assertFalse( Urbby_WC_Mapper::verify_signature( $body, $sig, 'otro' ) );
		$this->assertFalse( Urbby_WC_Mapper::verify_signature( $body, '', 'whsec' ) );
		// Sin secreto configurado no se acepta nada, ni una firma calculada con secreto vacío.
		$this->assertFalse( Urbby_WC_Mapper::verify_signature( $body, hash_hmac( 'sha256', $body, '' ), '' ) );
	}

	public function test_la_reference_es_estable_y_cambia_si_cambian_las_lineas_o_el_sitio() {
		$a    = array(
			array( 'variantId' => 'v1', 'quantity' => 2 ),
			array( 'variantId' => 'v2', 'quantity' => 1 ),
		);
		$site = 'https://tienda.com';
		$ref  = Urbby_WC_Mapper::reservation_reference( 42, $a, $site );
		$this->assertMatchesRegularExpression( '/^woo-[0-9a-f]{8}-order-42-[0-9a-f]{10}$/', $ref );
		$this->assertSame( $ref, Urbby_WC_Mapper::reservation_reference( 42, array_reverse( $a ), $site ), 'el orden de las líneas no importa' );
		$this->assertSame( $ref, Urbby_WC_Mapper::reservation_reference( 42, $a, 'https://TIENDA.com/' ), 'la barra final y las mayúsculas no importan' );
		$this->assertNotSame( $ref, Urbby_WC_Mapper::reservation_reference( 42, array( array( 'variantId' => 'v1', 'quantity' => 3 ) ), $site ) );
		$this->assertNotSame( $ref, Urbby_WC_Mapper::reservation_reference( 43, $a, $site ) );
		$this->assertNotSame( $ref, Urbby_WC_Mapper::reservation_reference( 42, $a, 'https://staging.tienda.com' ), 'una copia de la tienda no comparte reservas' );
		$this->assertLessThanOrEqual( 120, strlen( $ref . '-' . time() ), 'cabe en el máximo de Urbby aun con el sufijo de reintento' );
	}

	public function test_las_lineas_de_la_misma_variante_se_suman() {
		$this->assertSame(
			array(
				array( 'variantId' => 'v1', 'quantity' => 3 ),
				array( 'variantId' => 'v2', 'quantity' => 1 ),
			),
			Urbby_WC_Mapper::merge_lines(
				array(
					array( 'variantId' => 'v1', 'quantity' => 1 ),
					array( 'variantId' => 'v2', 'quantity' => 1 ),
					array( 'variantId' => 'v1', 'quantity' => 2 ),
				)
			)
		);
	}

	public function test_los_montos_salen_con_dos_decimales_sin_pasar_por_float() {
		$this->assertSame( '12.50', Urbby_WC_Mapper::money( '12.5' ) );
		$this->assertSame( '12.50', Urbby_WC_Mapper::money( '12,50' ) );
		$this->assertSame( '12.00', Urbby_WC_Mapper::money( '12' ) );
		$this->assertSame( '12.50', Urbby_WC_Mapper::money( '12.495' ) );
		$this->assertSame( '12.49', Urbby_WC_Mapper::money( '12.494' ) );
		$this->assertSame( '0.30', Urbby_WC_Mapper::money( '0.3' ) );
		// 0.1 + 0.2 en float da 0.30000000000000004: acá nunca se suma en float.
		$this->assertSame( '1000.10', Urbby_WC_Mapper::money( ' 1000.1 ' ) );
		$this->assertSame( '10.00', Urbby_WC_Mapper::money( '9.999' ) );
		foreach ( array( '', '0', '0.00', '-5', 'abc', '1.2.3', null ) as $malo ) {
			$this->assertNull( Urbby_WC_Mapper::money( $malo ), var_export( $malo, true ) );
		}
	}

	public function test_un_cambio_de_stock_viaja_como_diferencia() {
		$this->assertSame( array( 'variantId' => 'v', 'mode' => 'delta', 'amount' => 5 ), Urbby_WC_Mapper::stock_delta_row( 'v', 5 ) );
		$this->assertSame( array( 'variantId' => 'v', 'mode' => 'sale', 'amount' => 3 ), Urbby_WC_Mapper::stock_delta_row( 'v', -3 ) );
	}

	public function test_nombre_y_texto() {
		$this->assertSame( array( 'Ana María', 'López' ), Urbby_WC_Mapper::split_name( '  Ana María   López ' ) );
		$this->assertSame( array( 'Cher', '' ), Urbby_WC_Mapper::split_name( 'Cher' ) );
		$this->assertSame( "Hola & chau\nlínea", Urbby_WC_Mapper::plain_text( '<p>Hola &amp; <b>chau</b></p>línea', 2000 ) );
		$this->assertSame( 'abc', Urbby_WC_Mapper::plain_text( 'abcdef', 3 ) );
	}

	public function test_la_llave_de_opciones_ignora_mayusculas_espacios_y_faltantes() {
		$this->assertSame( Urbby_WC_Mapper::option_key( array( 'M', ' Rojo ' ) ), Urbby_WC_Mapper::option_key( array( 'm', 'rojo', null ) ) );
		$this->assertNotSame( Urbby_WC_Mapper::option_key( array( 'M', 'Rojo' ) ), Urbby_WC_Mapper::option_key( array( 'Rojo', 'M' ) ) );
		$this->assertSame( Urbby_WC_Mapper::option_key( array( 'Único' ) ), Urbby_WC_Mapper::option_key( array( 'único' ) ) );
	}
}
