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

	/** Estados de Woo para los pasos del despacho en Urbby. */
	const STATUSES = array(
		'wc-urbby-preparando' => 'Preparando (Urbby)',
		'wc-urbby-listo'      => 'Lista para recolección (Urbby)',
		'wc-urbby-en-camino'  => 'En camino (Urbby)',
	);

	public static function boot() {
		Urbby_WC_Catalog::hooks();
		Urbby_WC_Stock::hooks();
		Urbby_WC_Orders::hooks();
		if ( is_admin() ) {
			Urbby_WC_Admin::hooks();
		}
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'order_statuses' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_schedules' ) );
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
				'label'       => 'Preparando (Urbby)',
				/* translators: %s: cantidad de órdenes. */
				'label_count' => _n_noop( 'Preparando (Urbby) <span class="count">(%s)</span>', 'Preparando (Urbby) <span class="count">(%s)</span>', 'urbby-woocommerce' ),
			)
		);
		register_post_status(
			'wc-urbby-listo',
			$common + array(
				'label'       => 'Lista para recolección (Urbby)',
				/* translators: %s: cantidad de órdenes. */
				'label_count' => _n_noop( 'Lista para recolección (Urbby) <span class="count">(%s)</span>', 'Listas para recolección (Urbby) <span class="count">(%s)</span>', 'urbby-woocommerce' ),
			)
		);
		register_post_status(
			'wc-urbby-en-camino',
			$common + array(
				'label'       => 'En camino (Urbby)',
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
				$out = array_merge( $out, self::STATUSES );
			}
		}
		return $out + self::STATUSES;
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

	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( Urbby_WC_Stock::ACTION_SYNC, Urbby_WC_Stock::ACTION_SYNC_PAGE, Urbby_WC_Orders::ACTION_RECONCILE, Urbby_WC_Catalog::ACTION_PUSH ) as $hook ) {
				as_unschedule_all_actions( $hook, null, 'urbby' );
			}
		}
	}
}
