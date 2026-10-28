<?php
/**
 * Al desinstalar: se desconecta de Urbby (revoca la credencial; ahí los productos que vinieron de esta
 * tienda pasan a borrador y se borra el webhook) y se borran la credencial y los ajustes. Los vínculos de
 * cada producto (`_urbby_*`) quedan: si se vuelve a instalar, los productos siguen cruzados con Urbby y no
 * se vuelve a migrar el stock (lo que pisaría el de Urbby).
 *
 * @package UrbbyWooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// WordPress corre este archivo solo, sin cargar el plugin: se cargan las clases que usa el cliente.
defined( 'URBBY_WC_VERSION' ) || define( 'URBBY_WC_VERSION', 'uninstall' );
require_once __DIR__ . '/includes/class-urbby-wc-settings.php';
require_once __DIR__ . '/includes/class-urbby-wc-response.php';
require_once __DIR__ . '/includes/class-urbby-wc-api.php';

// Al mejor esfuerzo y con un timeout corto: si Urbby no contesta, la desinstalación sigue igual.
// Una copia del sitio (la dirección cambió) no revoca la credencial de la original: `Urbby_WC_Api` no llama.
if ( '' !== Urbby_WC_Settings::get()['client_id'] ) {
	Urbby_WC_Api::revoke_credential( 5 );
}

delete_option( Urbby_WC_Settings::OPTION );
delete_transient( 'urbby_wc_categories' );
