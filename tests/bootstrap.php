<?php
/**
 * Los tests unitarios corren sin WordPress: solo prueban las reglas puras (`Urbby_WC_Mapper`).
 * Lo que toca WooCommerce se prueba de verdad en `e2e/` contra un WordPress con WooCommerce.
 */
define( 'URBBY_WC_TESTS', true );
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-urbby-wc-mapper.php';
require dirname( __DIR__ ) . '/includes/class-urbby-wc-response.php';
