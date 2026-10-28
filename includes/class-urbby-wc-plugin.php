<?php
/**
 * Arranque: hooks, estados de orden propios y las tareas periódicas.
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Punto de entrada.
 */
final class Urbby_WC_Plugin {

	/** Cada 30 minutos: copia del stock de Urbby y repaso de pedidos. */
	const EVERY = 1800;

	public static function boot() {
		Urbby_WC_Catalog::hooks();
		Urbby_WC_Stock::hooks();
		Urbby_WC_Orders::hooks();
		if ( is_admin() ) {
			Urbby_WC_Admin::hooks();
			Urbby_WC_Connect::hooks();
		}
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'order_statuses' ) );
		// Solo en el admin y en el cron: no en cada visita a la tienda.
		if ( is_admin() || wp_doing_cron() ) {
			add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_schedules' ) );
		}
	}

	/**
	 * Estados de Woo para los pasos del despacho en Urbby.
	 *
	 * @return array `[ 'wc-…' => etiqueta ]`.
	 */
	public static function statuses() {
		return array(
			'wc-urbby-preparando' => __( 'Preparando (Urbby)', 'urbby-woocommerce' ),
			'wc-urbby-listo'      => __( 'Lista para recolección (Urbby)', 'urbby-woocommerce' ),
			'wc-urbby-en-camino'  => __( 'En camino (Urbby)', 'urbby-woocommerce' ),
		);
	}

	public static function register_statuses() {
		$common = array(
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
		);
		register_post_status(
			'wc-urbby-preparando',
			$common + array(
				'label'       => __( 'Preparando (Urbby)', 'urbby-woocommerce' ),
				/* translators: %s: cantidad de órdenes. */
				'label_count' => _n_noop( 'Preparando (Urbby) <span class="count">(%s)</span>', 'Preparando (Urbby) <span class="count">(%s)</span>', 'urbby-woocommerce' ),
			)
		);
		register_post_status(
			'wc-urbby-listo',
			$common + array(
				'label'       => __( 'Lista para recolección (Urbby)', 'urbby-woocommerce' ),
				/* translators: %s: cantidad de órdenes. */
				'label_count' => _n_noop( 'Lista para recolección (Urbby) <span class="count">(%s)</span>', 'Listas para recolección (Urbby) <span class="count">(%s)</span>', 'urbby-woocommerce' ),
			)
		);
		register_post_status(
			'wc-urbby-en-camino',
			$common + array(
				'label'       => __( 'En camino (Urbby)', 'urbby-woocommerce' ),
				/* translators: %s: cantidad de órdenes. */
				'label_count' => _n_noop( 'En camino (Urbby) <span class="count">(%s)</span>', 'En camino (Urbby) <span class="count">(%s)</span>', 'urbby-woocommerce' ),
			)
		);
	}

	/**
	 * Los estados propios van después de «Procesando».
	 *
	 * @param array $statuses Estados de Woo.
	 * @return array
	 */
	public static function order_statuses( $statuses ) {
		$out = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$out = array_merge( $out, self::statuses() );
			}
		}
		return $out + self::statuses();
	}

	public static function ensure_schedules() {
		if ( ! Urbby_WC_Settings::connected() || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		foreach ( array( Urbby_WC_Stock::ACTION_SYNC, Urbby_WC_Orders::ACTION_RECONCILE ) as $hook ) {
			if ( ! as_has_scheduled_action( $hook, array(), 'urbby' ) ) {
				as_schedule_recurring_action( time() + 60, self::EVERY, $hook, array(), 'urbby' );
			}
		}
	}

	/** Frena las tareas periódicas y pendientes del plugin. */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( Urbby_WC_Stock::ACTION_SYNC, Urbby_WC_Stock::ACTION_SYNC_PAGE, Urbby_WC_Orders::ACTION_RECONCILE, Urbby_WC_Catalog::ACTION_PUSH, Urbby_WC_Stock::ACTION_REFRESH ) as $hook ) {
				as_unschedule_all_actions( $hook, null, 'urbby' );
			}
		}
	}

	/**
	 * Al desactivar: se desconecta de Urbby (revoca la credencial; ahí los productos `woo-*` pasan a
	 * borrador) y se frenan las tareas. Si Urbby no contesta en unos segundos la credencial se queda
	 * guardada y la desactivación sigue: reactivar el plugin retoma la conexión.
	 */
	public static function deactivate() {
		Urbby_WC_Connect::disconnect( 5 );
		self::unschedule();
	}
}
