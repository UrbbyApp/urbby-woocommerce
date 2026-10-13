<?php
/**
 * Pantalla WooCommerce → Urbby y la columna «Urbby» de la lista de productos.
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ajustes, acciones manuales y estado de cada producto.
 */
final class Urbby_WC_Admin {

	const CAP  = 'manage_woocommerce';
	const PAGE = 'urbby-woocommerce';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_urbby_wc_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_urbby_wc_action', array( __CLASS__, 'action' ) );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Urbby', 'Urbby', self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Las categorías de Urbby, cacheadas una hora.
	 *
	 * @return array `[ id => nombre ]`.
	 */
	private static function urbby_categories() {
		$cached = get_transient( 'urbby_wc_categories' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = Urbby_WC_Api::categories();
		$out = array();
		if ( $res->ok() ) {
			foreach ( (array) $res->body['data'] as $c ) {
				$out[ $c['id'] ] = $c['name'];
			}
			set_transient( 'urbby_wc_categories', $out, HOUR_IN_SECONDS );
		}
		return $out;
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s          = Urbby_WC_Settings::get();
		$categories = self::urbby_categories();
		$woo_cats   = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$notice     = (string) get_transient( 'urbby_wc_notice_' . get_current_user_id() );
		delete_transient( 'urbby_wc_notice_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1>Urbby</h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( Urbby_WC_Settings::moved() ) : ?>
				<div class="notice notice-error"><p>Este sitio no es el que se conectó a Urbby (¿es una copia o cambió de dirección?). No habla con Urbby hasta que guardes la conexión de nuevo desde acá.</p></div>
			<?php endif; ?>
			<p>El stock de los productos vinculados vive en Urbby: tu tienda y Urbby descuentan del mismo lugar. Los cambios de stock que hagas acá se mandan a Urbby como diferencia.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="urbby_wc_save">
				<?php wp_nonce_field( 'urbby_wc_save' ); ?>
				<h2>Conexión</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">API</th>
						<td><code><?php echo esc_html( Urbby_WC_Settings::api_base() ); ?></code>
						<p class="description">Para pruebas se cambia con la constante <code>URBBY_WC_API_BASE</code> en <code>wp-config.php</code>.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="urbby-client-id">Client ID</label></th>
						<td><input id="urbby-client-id" type="text" name="client_id" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $s['client_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="urbby-client-secret">Client secret</label></th>
						<td><input id="urbby-client-secret" type="password" name="client_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['client_secret'] ? esc_attr( 'Guardado. Escribí otro para cambiarlo.' ) : ''; ?>">
						<p class="description">Credencial del portal de Urbby (Integraciones) con permisos de catálogo (leer y escribir), inventario y pedidos (leer).</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="urbby-webhook-secret">Secreto del webhook</label></th>
						<td><input id="urbby-webhook-secret" type="password" name="webhook_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['webhook_secret'] ? esc_attr( 'Guardado. Escribí otro para cambiarlo.' ) : ''; ?>">
						<p class="description">En el portal de Urbby creá un webhook a <code><?php echo esc_html( rest_url( 'urbby/v1/webhook' ) ); ?></code> con los eventos <code>order_created</code>, <code>order_status_changed</code> y <code>shipment_status_changed</code>, y pegá acá su secreto.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="urbby-hold">Minutos de reserva</label></th>
						<td><input id="urbby-hold" type="number" min="1" max="1440" name="hold_minutes" value="<?php echo esc_attr( (string) $s['hold_minutes'] ); ?>">
						<p class="description">Cuánto se aparta el stock de un pedido de tu tienda mientras se paga. Si vence sin pago, vuelve solo.</p></td>
					</tr>
				</table>

				<h2>Categorías</h2>
				<?php if ( ! $categories ) : ?>
					<p>Guardá la conexión para ver las categorías de Urbby.</p>
				<?php else : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="urbby-default-cat">Por defecto</label></th>
							<td><?php self::category_select( 'default_category', $s['default_category'], $categories, 'urbby-default-cat' ); ?></td>
						</tr>
						<?php foreach ( is_array( $woo_cats ) ? $woo_cats : array() as $term ) : ?>
							<tr>
								<th scope="row"><label for="urbby-cat-<?php echo esc_attr( (string) $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></label></th>
								<td><?php self::category_select( 'category_map[' . $term->term_id . ']', isset( $s['category_map'][ $term->term_id ] ) ? $s['category_map'][ $term->term_id ] : '', $categories, 'urbby-cat-' . $term->term_id ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>
				<?php submit_button( 'Guardar' ); ?>
			</form>

			<h2>Acciones</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="urbby_wc_action">
				<?php wp_nonce_field( 'urbby_wc_action' ); ?>
				<button class="button" name="do" value="test">Probar conexión</button>
				<button class="button button-primary" name="do" value="push_all">Enviar catálogo a Urbby</button>
				<button class="button" name="do" value="sync_stock">Traer stock de Urbby ahora</button>
				<button class="button" name="do" value="reconcile">Revisar pedidos de Urbby ahora</button>
			</form>
			<p class="description">Lo que falla al enviar un producto aparece en la columna «Urbby» de la lista de productos. El detalle técnico está en WooCommerce → Estado → Registros (fuente <code>urbby</code>).</p>
		</div>
		<?php
	}

	/**
	 * @param string $name       Campo.
	 * @param string $current    Valor elegido.
	 * @param array  $categories Categorías de Urbby.
	 * @param string $id         Id del control.
	 */
	private static function category_select( $name, $current, array $categories, $id ) {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">—</option>';
		foreach ( $categories as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sin permiso.', 403 );
		}
		check_admin_referer( 'urbby_wc_save' );
		$post  = wp_unslash( $_POST );
		$patch = array(
			'site'             => home_url(),
			'client_id'        => isset( $post['client_id'] ) ? sanitize_text_field( $post['client_id'] ) : '',
			'hold_minutes'     => isset( $post['hold_minutes'] ) ? min( 1440, max( 1, (int) $post['hold_minutes'] ) ) : 60,
			'default_category' => isset( $post['default_category'] ) ? sanitize_text_field( $post['default_category'] ) : '',
			'category_map'     => array(),
		);
		foreach ( isset( $post['category_map'] ) && is_array( $post['category_map'] ) ? $post['category_map'] : array() as $term_id => $urbby_id ) {
			if ( '' !== $urbby_id ) {
				$patch['category_map'][ (int) $term_id ] = sanitize_text_field( $urbby_id );
			}
		}
		// Los secretos vacíos se conservan: el formulario no los vuelve a mostrar.
		foreach ( array( 'client_secret', 'webhook_secret' ) as $secret ) {
			if ( ! empty( $post[ $secret ] ) ) {
				$patch[ $secret ] = trim( (string) $post[ $secret ] );
			}
		}
		Urbby_WC_Settings::update( $patch );
		delete_transient( 'urbby_wc_categories' );
		Urbby_WC_Plugin::ensure_schedules();
		self::back( 'Ajustes guardados.' );
	}

	public static function action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sin permiso.', 403 );
		}
		check_admin_referer( 'urbby_wc_action' );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'test' === $do ) {
			self::back( self::test_connection() );
		}
		if ( 'push_all' === $do ) {
			self::back( 'Se encolaron ' . Urbby_WC_Catalog::push_all() . ' productos para enviar a Urbby. Avanza en segundo plano.' );
		}
		if ( 'sync_stock' === $do ) {
			as_enqueue_async_action( Urbby_WC_Stock::ACTION_SYNC_PAGE, array( '' ), 'urbby' );
			self::back( 'Se está trayendo el stock de Urbby en segundo plano.' );
		}
		if ( 'reconcile' === $do ) {
			as_enqueue_async_action( Urbby_WC_Orders::ACTION_RECONCILE, array(), 'urbby' );
			self::back( 'Se están revisando los pedidos de Urbby en segundo plano.' );
		}
		self::back( '' );
	}

	/**
	 * @param string $notice Aviso para la pantalla.
	 */
	private static function back( $notice ) {
		if ( '' !== $notice ) {
			set_transient( 'urbby_wc_notice_' . get_current_user_id(), $notice, 60 );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Prueba la credencial y CADA permiso que el plugin necesita, sin escribir nada: sin
	 * `inventory:write` toda venta de la tienda falla, y eso tiene que verse acá y no en el checkout.
	 *
	 * @return string
	 */
	private static function test_connection() {
		$checks = array(
			'catalog:read'    => array( 'GET', '/public/catalog/products?limit=1', null, array( 200 ) ),
			// Un lote vacío no pasa la validación (400): llegar a validar prueba el permiso sin escribir.
			'catalog:write'   => array( 'POST', '/public/catalog/products/bulk', array( 'items' => array() ), array( 400 ) ),
			// Una reserva que no existe: 404 con el permiso, 403 sin él.
			'inventory:write' => array( 'GET', '/public/stock-reservations/00000000-0000-4000-8000-000000000000', null, array( 404 ) ),
			'orders:read'     => array( 'GET', '/public/orders?limit=1', null, array( 200 ) ),
		);
		$missing = array();
		foreach ( $checks as $scope => $c ) {
			$res = Urbby_WC_Api::request( $c[0], $c[1], $c[2] );
			if ( 0 === $res->status || 401 === $res->status ) {
				return 'Conexión con Urbby: ' . ( 401 === $res->status ? 'la credencial no es válida.' : $res->message() );
			}
			if ( ! in_array( $res->status, $c[3], true ) ) {
				$missing[] = $scope;
			}
		}
		return $missing ? 'Conexión con Urbby: a la credencial le faltan permisos: ' . implode( ', ', $missing ) . '. Creá otra en el portal de Urbby con todos.' : 'Conexión con Urbby: bien, con todos los permisos.';
	}

	/**
	 * @param array $columns Columnas.
	 * @return array
	 */
	public static function column( $columns ) {
		$columns['urbby'] = 'Urbby';
		return $columns;
	}

	/**
	 * @param string $column  Columna.
	 * @param int    $post_id Producto.
	 */
	public static function column_value( $column, $post_id ) {
		if ( 'urbby' !== $column ) {
			return;
		}
		$error = get_post_meta( $post_id, Urbby_WC_Catalog::META_ERROR, true );
		if ( $error ) {
			echo '<span style="color:#b32d2e">' . esc_html( $error ) . '</span>';
		} elseif ( wc_get_product( $post_id ) && Urbby_WC_Catalog::fully_linked( wc_get_product( $post_id ) ) ) {
			echo '<span aria-label="Vinculado">✓</span>';
		} elseif ( get_post_meta( $post_id, Urbby_WC_Catalog::META_PRODUCT, true ) ) {
			echo '<span>Vinculando…</span>';
		} else {
			echo '<span aria-label="No enviado">—</span>';
		}
	}
}
