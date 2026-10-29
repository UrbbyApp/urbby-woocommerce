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
		// Sin credencial no hay nada que pedir (y un 401 de balde marcaría la conexión como revocada).
		if ( '' === Urbby_WC_Settings::get()['client_id'] ) {
			return array();
		}
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
		$notice     = get_transient( 'urbby_wc_notice_' . get_current_user_id() );
		delete_transient( 'urbby_wc_notice_' . get_current_user_id() );
		$has_creds = '' !== $s['client_id'];
		?>
		<div class="wrap">
			<h1>Urbby</h1>
			<?php if ( is_array( $notice ) && '' !== $notice['text'] ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( Urbby_WC_Settings::moved() ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Este sitio no es el que se conectó a Urbby (¿es una copia o cambió de dirección?). No habla con Urbby hasta que vuelvas a conectar desde acá.', 'urbby-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $s['auth_failed'] ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Urbby rechazó la credencial: la conexión se revocó (por ejemplo, al desconectar desde el portal) o dejó de valer. Mientras tanto tu tienda vende con su propio stock. Tocá «Conectar con Urbby» para volver a conectar.', 'urbby-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'El stock de los productos vinculados vive en Urbby: tu tienda y Urbby descuentan del mismo lugar. Los cambios de stock que hagas acá se mandan a Urbby como diferencia.', 'urbby-woocommerce' ); ?></p>

			<h2><?php esc_html_e( 'Conexión', 'urbby-woocommerce' ); ?></h2>
			<?php if ( Urbby_WC_Settings::connected() ) : ?>
				<p>
					<?php
					if ( 'connect' === $s['connection'] ) {
						printf(
							/* translators: %s: nombre del comercio en Urbby. */
							esc_html__( 'Conectado con Urbby: %s.', 'urbby-woocommerce' ),
							'<strong>' . esc_html( $s['store_name'] ) . '</strong>'
						);
					} else {
						esc_html_e( 'Conectado con Urbby con una credencial pegada a mano.', 'urbby-woocommerce' );
					}
					?>
				</p>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Desconectar (también desactivar o desinstalar este plugin) revoca la credencial y pasa a borrador, en Urbby, los productos que vienen de tu tienda. No se borra nada: al volver a conectar y enviar el catálogo se publican de nuevo según WooCommerce.', 'urbby-woocommerce' ); ?></p></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="urbby_wc_disconnect">
					<?php wp_nonce_field( 'urbby_wc_disconnect' ); ?>
					<button class="button" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( '¿Desconectar de Urbby? Tus productos pasan a borrador en Urbby.', 'urbby-woocommerce' ) ) ); ?>);"><?php esc_html_e( 'Desconectar', 'urbby-woocommerce' ); ?></button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Conectá tu tienda con tu cuenta de comercio de Urbby: te llevamos a Urbby para que autorices y volvés acá ya conectado. El dueño del comercio es quien autoriza.', 'urbby-woocommerce' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="urbby_wc_connect">
					<?php wp_nonce_field( 'urbby_wc_connect' ); ?>
					<button class="button button-primary button-hero"><?php esc_html_e( 'Conectar con Urbby', 'urbby-woocommerce' ); ?></button>
				</form>
				<?php if ( $has_creds ) : ?>
					<p class="description"><?php esc_html_e( 'Si antes habías pegado una credencial y un webhook a mano, al conectar se reemplazan por los nuevos: borrá el webhook viejo en el portal de Urbby (Integraciones).', 'urbby-woocommerce' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="urbby_wc_save">
				<?php wp_nonce_field( 'urbby_wc_save' ); ?>
				<details style="margin:1em 0"<?php echo ! Urbby_WC_Settings::connected() && $has_creds ? ' open' : ''; ?>>
					<summary><strong><?php esc_html_e( 'Avanzado: pegar la credencial a mano', 'urbby-woocommerce' ); ?></strong></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">API</th>
							<td><code><?php echo esc_html( Urbby_WC_Settings::api_base() ); ?></code>
							<p class="description">
								<?php
								printf(
									/* translators: %s: nombre de una constante de PHP. */
									esc_html__( 'Para pruebas se cambia con la constante %s en wp-config.php.', 'urbby-woocommerce' ),
									'<code>URBBY_WC_API_BASE</code>'
								);
								?>
							</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="urbby-client-id">Client ID</label></th>
							<td><input id="urbby-client-id" type="text" name="client_id" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $s['client_id'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="urbby-client-secret">Client secret</label></th>
							<td><input id="urbby-client-secret" type="password" name="client_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['client_secret'] ? esc_attr__( 'Guardado. Escribí otro para cambiarlo.', 'urbby-woocommerce' ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'Credencial del portal de Urbby (Integraciones) con permisos de catálogo (leer y escribir), inventario y pedidos (leer y escribir).', 'urbby-woocommerce' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="urbby-webhook-secret"><?php esc_html_e( 'Secreto del webhook', 'urbby-woocommerce' ); ?></label></th>
							<td><input id="urbby-webhook-secret" type="password" name="webhook_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['webhook_secret'] ? esc_attr__( 'Guardado. Escribí otro para cambiarlo.', 'urbby-woocommerce' ) : ''; ?>">
							<p class="description">
								<?php
								printf(
									/* translators: 1: URL del webhook de este sitio, 2: lista de eventos. */
									esc_html__( 'En el portal de Urbby creá un webhook a %1$s con los eventos %2$s, y pegá acá su secreto.', 'urbby-woocommerce' ),
									'<code>' . esc_html( rest_url( 'urbby/v1/webhook' ) ) . '</code>',
									'<code>order_created</code>, <code>order_status_changed</code>, <code>shipment_status_changed</code>'
								);
								?>
							</p></td>
						</tr>
					</table>
				</details>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="urbby-hold"><?php esc_html_e( 'Minutos de reserva', 'urbby-woocommerce' ); ?></label></th>
						<td><input id="urbby-hold" type="number" min="1" max="1440" name="hold_minutes" value="<?php echo esc_attr( (string) $s['hold_minutes'] ); ?>">
						<p class="description"><?php esc_html_e( 'Cuánto se aparta el stock de un pedido de tu tienda mientras se paga. Si vence sin pago, vuelve solo.', 'urbby-woocommerce' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Categorías', 'urbby-woocommerce' ); ?></h2>
				<?php if ( ! $categories ) : ?>
					<p><?php esc_html_e( 'Conectá tu tienda para ver las categorías de Urbby.', 'urbby-woocommerce' ); ?></p>
				<?php else : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="urbby-default-cat"><?php esc_html_e( 'Por defecto', 'urbby-woocommerce' ); ?></label></th>
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
				<?php submit_button( __( 'Guardar', 'urbby-woocommerce' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Acciones', 'urbby-woocommerce' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="urbby_wc_action">
				<?php wp_nonce_field( 'urbby_wc_action' ); ?>
				<button class="button" name="do" value="test"><?php esc_html_e( 'Probar conexión', 'urbby-woocommerce' ); ?></button>
				<button class="button button-primary" name="do" value="push_all"><?php esc_html_e( 'Enviar catálogo a Urbby', 'urbby-woocommerce' ); ?></button>
				<button class="button" name="do" value="sync_stock"><?php esc_html_e( 'Traer stock de Urbby ahora', 'urbby-woocommerce' ); ?></button>
				<button class="button" name="do" value="reconcile"><?php esc_html_e( 'Revisar pedidos de Urbby ahora', 'urbby-woocommerce' ); ?></button>
			</form>
			<p class="description">
				<?php
				printf(
					/* translators: %s: nombre de la fuente del registro. */
					esc_html__( 'Lo que falla al enviar un producto aparece en la columna «Urbby» de la lista de productos. El detalle técnico está en WooCommerce → Estado → Registros (fuente %s).', 'urbby-woocommerce' ),
					'<code>urbby</code>'
				);
				?>
			</p>
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
			wp_die( esc_html__( 'Sin permiso.', 'urbby-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'urbby_wc_save' );
		$post  = wp_unslash( $_POST );
		$saved = Urbby_WC_Settings::get();
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
		// Otra credencial pegada a mano ya no es la de «Conectar con Urbby», y vuelve a probarse.
		if ( $patch['client_id'] !== $saved['client_id'] || ! empty( $post['client_secret'] ) ) {
			$patch['connection']  = '';
			$patch['webhook_id']  = '';
			$patch['store_name']  = '';
			$patch['auth_failed'] = 0;
		}
		Urbby_WC_Settings::update( $patch );
		delete_transient( 'urbby_wc_categories' );
		Urbby_WC_Plugin::ensure_schedules();
		self::back( __( 'Ajustes guardados.', 'urbby-woocommerce' ) );
	}

	public static function action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'urbby-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'urbby_wc_action' );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'test' === $do ) {
			self::back( self::test_connection() );
		}
		if ( 'push_all' === $do ) {
			self::back(
				sprintf(
					/* translators: %d: cantidad de productos. */
					__( 'Se encolaron %d productos para enviar a Urbby. Avanza en segundo plano.', 'urbby-woocommerce' ),
					Urbby_WC_Catalog::push_all()
				)
			);
		}
		if ( 'sync_stock' === $do ) {
			as_enqueue_async_action( Urbby_WC_Stock::ACTION_SYNC_PAGE, array( '' ), 'urbby' );
			self::back( __( 'Se está trayendo el stock de Urbby en segundo plano.', 'urbby-woocommerce' ) );
		}
		if ( 'reconcile' === $do ) {
			as_enqueue_async_action( Urbby_WC_Orders::ACTION_RECONCILE, array(), 'urbby' );
			self::back( __( 'Se están revisando los pedidos de Urbby en segundo plano.', 'urbby-woocommerce' ) );
		}
		self::back( '' );
	}

	/**
	 * Vuelve a la pantalla con un aviso. Termina la petición.
	 *
	 * @param string $notice Aviso para la pantalla.
	 * @param string $type   `info`, `success` o `error`.
	 */
	public static function back( $notice, $type = 'info' ) {
		if ( '' !== $notice ) {
			set_transient(
				'urbby_wc_notice_' . get_current_user_id(),
				array(
					'type' => $type,
					'text' => $notice,
				),
				60
			);
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
		if ( '' === Urbby_WC_Settings::get()['client_id'] ) {
			return __( 'Todavía no hay credencial: tocá «Conectar con Urbby».', 'urbby-woocommerce' );
		}
		$checks = array(
			'catalog:read'    => array( 'GET', '/public/catalog/products?limit=1', null, array( 200 ) ),
			// Un lote vacío no pasa la validación (400): llegar a validar prueba el permiso sin escribir.
			'catalog:write'   => array( 'POST', '/public/catalog/products/bulk', array( 'items' => array() ), array( 400 ) ),
			// Una reserva que no existe: 404 con el permiso, 403 sin él.
			'inventory:write' => array( 'GET', '/public/stock-reservations/00000000-0000-4000-8000-000000000000', null, array( 404 ) ),
			'orders:read'     => array( 'GET', '/public/orders?limit=1', null, array( 200 ) ),
			// Un pedido que no existe: 404 con el permiso, 403 sin él (no cancela nada). Sin él, cancelar una
			// orden de Urbby en la tienda no la cancela en Urbby.
			'orders:write'    => array( 'POST', '/public/orders/00000000-0000-4000-8000-000000000000/cancel', null, array( 404 ) ),
		);
		$missing = array();
		foreach ( $checks as $scope => $c ) {
			$res = Urbby_WC_Api::request( $c[0], $c[1], $c[2] );
			if ( 0 === $res->status || 401 === $res->status ) {
				return __( 'Conexión con Urbby: ', 'urbby-woocommerce' ) . ( 401 === $res->status ? __( 'la credencial no es válida.', 'urbby-woocommerce' ) : $res->message() );
			}
			if ( ! in_array( $res->status, $c[3], true ) ) {
				$missing[] = $scope;
			}
		}
		if ( ! $missing ) {
			return __( 'Conexión con Urbby: bien, con todos los permisos.', 'urbby-woocommerce' );
		}
		return sprintf(
			/* translators: %s: permisos que faltan, separados por coma. */
			__( 'Conexión con Urbby: a la credencial le faltan permisos: %s. Tocá «Conectar con Urbby» para crear una con todos.', 'urbby-woocommerce' ),
			implode( ', ', $missing )
		);
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
			echo '<span aria-label="' . esc_attr__( 'Vinculado', 'urbby-woocommerce' ) . '">✓</span>';
		} elseif ( get_post_meta( $post_id, Urbby_WC_Catalog::META_PRODUCT, true ) ) {
			echo '<span>' . esc_html__( 'Vinculando…', 'urbby-woocommerce' ) . '</span>';
		} else {
			echo '<span aria-label="' . esc_attr__( 'No enviado', 'urbby-woocommerce' ) . '">—</span>';
		}
	}
}
