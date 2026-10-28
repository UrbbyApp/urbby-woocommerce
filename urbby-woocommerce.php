<?php
/**
 * Plugin Name:          Urbby para WooCommerce
 * Plugin URI:           https://github.com/UrbbyApp/urbby-woocommerce
 * Description:          Vendé tu catálogo de WooCommerce en Urbby. El stock vive en Urbby: tu tienda y Urbby descuentan del mismo lugar, así nunca venden la misma unidad dos veces.
 * Version:              0.2.0
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Urbby
 * Author URI:           https://urbby.app
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          urbby-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 * Tested up to:         7.1
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'URBBY_WC_VERSION', '0.2.0' );
define( 'URBBY_WC_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-urbby-wc-mapper.php';
require_once __DIR__ . '/includes/class-urbby-wc-settings.php';
require_once __DIR__ . '/includes/class-urbby-wc-response.php';
require_once __DIR__ . '/includes/class-urbby-wc-api.php';
require_once __DIR__ . '/includes/class-urbby-wc-log.php';
require_once __DIR__ . '/includes/class-urbby-wc-catalog.php';
require_once __DIR__ . '/includes/class-urbby-wc-stock.php';
require_once __DIR__ . '/includes/class-urbby-wc-orders.php';
require_once __DIR__ . '/includes/class-urbby-wc-admin.php';
require_once __DIR__ . '/includes/class-urbby-wc-connect.php';
require_once __DIR__ . '/includes/class-urbby-wc-plugin.php';

// Traducciones del plugin (los textos fuente están en español).
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'urbby-woocommerce', false, dirname( plugin_basename( URBBY_WC_FILE ) ) . '/languages' );
	},
	0
);

// Tablas de órdenes nuevas de WooCommerce (HPOS): el plugin solo usa la API CRUD de órdenes.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', URBBY_WC_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			Urbby_WC_Plugin::boot();
		}
	}
);

register_deactivation_hook( __FILE__, array( 'Urbby_WC_Plugin', 'deactivate' ) );
