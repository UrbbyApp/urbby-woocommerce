<?php
/**
 * Al desinstalar: se borran la credencial y los ajustes. Los vínculos de cada producto (`_urbby_*`)
 * quedan: si se vuelve a instalar, los productos siguen cruzados con Urbby y no se vuelve a migrar el
 * stock (lo que pisaría el de Urbby).
 *
 * @package UrbbyWooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'urbby_wc_settings' );
delete_transient( 'urbby_wc_categories' );
