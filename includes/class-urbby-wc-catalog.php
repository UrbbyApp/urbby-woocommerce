<?php
/**
 * Catálogo de Woo → Urbby: contenido y precio. El stock solo viaja la primera vez (la migración):
 * desde ahí lo lleva Urbby.
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sube productos a Urbby y guarda con qué variante de Urbby se cruza cada uno.
 */
final class Urbby_WC_Catalog {

	const META_PRODUCT = '_urbby_product_id';
	const META_VARIANT = '_urbby_variant_id';
	const META_ERROR   = '_urbby_sync_error';
	const META_IMAGES  = '_urbby_images_hash';
	const ACTION_PUSH  = 'urbby_wc_push_products';

	/** Lo que Urbby acepta por producto. */
	const MAX_OPTIONS  = 3;
	const MAX_VARIANTS = 12;
	const MAX_IMAGES   = 5;

	public static function hooks() {
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'queue_variation' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_variation' ) );
		add_action( 'trashed_post', array( __CLASS__, 'queue' ) );
		add_action( self::ACTION_PUSH, array( __CLASS__, 'push' ) );
	}

	/**
	 * Encola la subida de un producto, unos segundos después: un guardado dispara varios hooks y se
	 * sube una sola vez.
	 *
	 * @param int $product_id Producto.
	 */
	public static function queue( $product_id ) {
		if ( Urbby_WC_Stock::$writing || ! Urbby_WC_Settings::connected() || 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		$args = array( array( (int) $product_id ) );
		if ( ! as_has_scheduled_action( self::ACTION_PUSH, $args, 'urbby' ) ) {
			as_schedule_single_action( time() + 10, self::ACTION_PUSH, $args, 'urbby' );
		}
	}

	/**
	 * @param int $variation_id Variación: se sube su producto.
	 */
	public static function queue_variation( $variation_id ) {
		$parent = wp_get_post_parent_id( $variation_id );
		if ( $parent ) {
			self::queue( $parent );
		}
	}

	/**
	 * Encola todo el catálogo en lotes de 50. Devuelve cuántos productos.
	 *
	 * @return int
	 */
	public static function push_all() {
		$ids = wc_get_products(
			array(
				'limit'  => -1,
				'return' => 'ids',
				'type'   => array( 'simple', 'variable' ),
				'status' => array( 'publish', 'draft', 'pending', 'private' ),
			)
		);
		foreach ( array_chunk( $ids, 50 ) as $i => $chunk ) {
			as_schedule_single_action( time() + $i * 5, self::ACTION_PUSH, array( array_map( 'intval', $chunk ) ), 'urbby' );
		}
		return count( $ids );
	}

	/**
	 * Sube un lote (hasta 100) a `POST /public/catalog/products/bulk`. Una fila mala no frena las demás:
	 * su motivo queda en el producto y se ve en la lista de productos.
	 *
	 * @param int[] $ids Productos.
	 */
	public static function push( $ids ) {
		$items = array();
		$sent  = array();
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$built = self::build( $product );
			if ( is_wp_error( $built ) ) {
				if ( 'skip' !== $built->get_error_code() ) {
					update_post_meta( $product->get_id(), self::META_ERROR, $built->get_error_message() );
				}
				continue;
			}
			$items[] = $built['item'];
			$sent[]  = array(
				'product'     => $product,
				'images_hash' => $built['images_hash'],
			);
		}
		if ( ! $items ) {
			return;
		}

		$res = Urbby_WC_Api::bulk_products( array_slice( $items, 0, 100 ) );
		if ( ! $res->ok() ) {
			Urbby_WC_Log::error(
				'No se pudo subir el lote de productos.',
				array(
					'status' => $res->status,
					'code'   => $res->code(),
				)
			);
			if ( $res->retryable() ) {
				as_schedule_single_action( time() + 300, self::ACTION_PUSH, array( array_map( 'intval', (array) $ids ) ), 'urbby' );
			}
			return;
		}

		foreach ( (array) $res->body['results'] as $row ) {
			$entry = isset( $sent[ $row['index'] ] ) ? $sent[ $row['index'] ] : null;
			if ( ! $entry ) {
				continue;
			}
			$product = $entry['product'];
			if ( 'failed' === $row['status'] ) {
				update_post_meta( $product->get_id(), self::META_ERROR, self::row_error( $row ) );
				continue;
			}
			update_post_meta( $product->get_id(), self::META_PRODUCT, $row['productId'] );
			update_post_meta( $product->get_id(), self::META_IMAGES, $entry['images_hash'] );
			delete_post_meta( $product->get_id(), self::META_ERROR );
			self::link_variants( $product, $row['productId'] );
		}
	}

	/**
	 * El mensaje de una fila fallida, con el primer campo que Urbby objetó.
	 *
	 * @param array $row Resultado de la fila.
	 * @return string
	 */
	private static function row_error( array $row ) {
		$message = isset( $row['error']['message'] ) ? $row['error']['message'] : 'Urbby rechazó este producto.';
		return $message;
	}

	/**
	 * Cruza cada variación de Woo con su variante de Urbby por los valores de sus opciones, y deja el
	 * stock de Woo igual al de Urbby.
	 *
	 * @param WC_Product $product          Producto de Woo.
	 * @param string     $urbby_product_id Producto de Urbby.
	 */
	public static function link_variants( WC_Product $product, $urbby_product_id ) {
		$res = Urbby_WC_Api::product( $urbby_product_id );
		if ( ! $res->ok() || empty( $res->body['variants'] ) ) {
			Urbby_WC_Log::error(
				'No se pudieron leer las variantes de Urbby.',
				array(
					'product' => $product->get_id(),
					'status'  => $res->status,
				)
			);
			return;
		}
		if ( ! $product->is_type( 'variable' ) ) {
			self::link( $product, $res->body['variants'][0] );
			return;
		}
		$by_key = array();
		foreach ( $res->body['variants'] as $v ) {
			$by_key[ Urbby_WC_Mapper::option_key( array( $v['option1'], $v['option2'], $v['option3'] ) ) ] = $v;
		}
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}
			$key = Urbby_WC_Mapper::option_key( self::variation_values( $product, $variation ) );
			if ( isset( $by_key[ $key ] ) ) {
				self::link( $variation, $by_key[ $key ] );
			}
		}
	}

	/**
	 * @param WC_Product $woo     Producto simple o variación.
	 * @param array      $variant Variante de Urbby.
	 */
	private static function link( WC_Product $woo, array $variant ) {
		update_post_meta( $woo->get_id(), self::META_VARIANT, $variant['id'] );
		Urbby_WC_Stock::write_display( $woo, (int) $variant['stock'] );
	}

	/**
	 * Los valores de las opciones de una variación, en el orden de los atributos del producto.
	 *
	 * @param WC_Product           $product   Producto variable.
	 * @param WC_Product_Variation $variation Variación.
	 * @return string[] Un '' donde la variación acepta cualquier valor.
	 */
	public static function variation_values( WC_Product $product, WC_Product $variation ) {
		$chosen = $variation->get_variation_attributes( false );
		$values = array();
		foreach ( self::variation_attributes( $product ) as $key => $attribute ) {
			$raw = isset( $chosen[ $key ] ) ? (string) $chosen[ $key ] : '';
			if ( '' !== $raw && $attribute->is_taxonomy() ) {
				$term = get_term_by( 'slug', $raw, $attribute->get_name() );
				$raw  = $term ? $term->name : $raw;
			}
			$values[] = $raw;
		}
		return $values;
	}

	/**
	 * @param WC_Product $product Producto variable.
	 * @return WC_Product_Attribute[] Los atributos que arman variaciones, por clave.
	 */
	private static function variation_attributes( WC_Product $product ) {
		return array_filter(
			$product->get_attributes(),
			function ( $a ) {
				return $a instanceof WC_Product_Attribute && $a->get_variation();
			}
		);
	}

	/**
	 * La fila de Urbby para un producto, o un error con el motivo para el comercio.
	 *
	 * @param WC_Product $product Producto.
	 * @return array|WP_Error `[ 'item' => array, 'images_hash' => string ]`.
	 */
	public static function build( WC_Product $product ) {
		$id     = $product->get_id();
		$mapped = (bool) get_post_meta( $id, self::META_PRODUCT, true );

		if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'unsupported', 'Urbby recibe productos simples y variables.' );
		}
		$post_status = $product->get_status();
		if ( 'trash' === $post_status ) {
			if ( ! $mapped ) {
				return new WP_Error( 'skip', '' );
			}
			$status = 'archived';
		} else {
			$status = 'publish' === $post_status ? 'published' : 'draft';
		}

		$title = Urbby_WC_Mapper::plain_text( $product->get_name(), 120 );
		if ( mb_strlen( $title ) < 8 ) {
			return new WP_Error( 'title', 'El nombre necesita al menos 8 caracteres para Urbby.' );
		}
		$description = Urbby_WC_Mapper::plain_text( $product->get_description() ? $product->get_description() : $product->get_short_description(), 2000 );
		if ( mb_strlen( $description ) < 20 ) {
			return new WP_Error( 'description', 'La descripción necesita al menos 20 caracteres para Urbby.' );
		}
		$category = Urbby_WC_Settings::urbby_category_for( $product->get_category_ids() );
		if ( '' === $category ) {
			return new WP_Error( 'category', 'Elegí a qué categoría de Urbby va (WooCommerce → Urbby → Categorías).' );
		}

		$item = array(
			'externalId'  => Urbby_WC_Mapper::EXTERNAL_PREFIX . $id,
			'title'       => $title,
			'description' => $description,
			'categoryId'  => $category,
			'status'      => $status,
		);

		// Las fotos solo viajan si cambiaron: cada vez que van, Urbby las baja y las vuelve a moderar.
		$image_ids = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		$urls      = array_slice( array_values( array_filter( array_map( 'wp_get_attachment_url', $image_ids ) ) ), 0, self::MAX_IMAGES );
		$hash      = sha1( implode( '|', $urls ) );
		if ( $urls && ( ! $mapped || get_post_meta( $id, self::META_IMAGES, true ) !== $hash ) ) {
			$item['imageUrls'] = $urls;
		}

		$extra = $product->is_type( 'variable' ) ? self::build_variable( $product ) : self::build_simple( $product );
		if ( is_wp_error( $extra ) ) {
			return $extra;
		}
		return array(
			'item'        => array_merge( $item, $extra ),
			'images_hash' => $hash,
		);
	}

	/**
	 * @param WC_Product $product Producto simple.
	 * @return array|WP_Error
	 */
	private static function build_simple( WC_Product $product ) {
		$prices = self::prices( $product );
		if ( is_wp_error( $prices ) ) {
			return $prices;
		}
		if ( true !== $product->get_manage_stock() ) {
			return new WP_Error( 'stock', 'Activá «Gestionar inventario» en este producto: Urbby necesita la cantidad.' );
		}
		// Solo la primera vez: es la migración del stock de Woo a Urbby. Después manda Urbby.
		if ( ! get_post_meta( $product->get_id(), self::META_VARIANT, true ) ) {
			$prices['stock'] = max( 0, (int) $product->get_stock_quantity() );
		}
		return $prices;
	}

	/**
	 * @param WC_Product $product Producto variable.
	 * @return array|WP_Error
	 */
	private static function build_variable( WC_Product $product ) {
		$attributes = self::variation_attributes( $product );
		if ( count( $attributes ) > self::MAX_OPTIONS ) {
			return new WP_Error( 'options', 'Urbby admite hasta 3 atributos que armen variaciones.' );
		}
		$options = array();
		foreach ( $attributes as $attribute ) {
			$options[] = array(
				'name'   => wc_attribute_label( $attribute->get_name(), $product ),
				'values' => $attribute->is_taxonomy()
					? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
					: array_values( $attribute->get_options() ),
			);
		}

		$variants  = array();
		$min_cents = null;
		$min_price = null;
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || 'publish' !== $variation->get_status() ) {
				continue;
			}
			$values = self::variation_values( $product, $variation );
			if ( in_array( '', $values, true ) ) {
				return new WP_Error( 'any', 'Una variación acepta «cualquier» valor de un atributo: Urbby necesita cada combinación por separado.' );
			}
			if ( true !== $variation->get_manage_stock() ) {
				return new WP_Error( 'stock', 'Activá «Gestionar inventario» en cada variación (no en el producto): Urbby lleva el stock por variación.' );
			}
			$prices = self::prices( $variation );
			if ( is_wp_error( $prices ) ) {
				return $prices;
			}
			$row      = array_merge(
				array(
					'option1' => isset( $values[0] ) ? $values[0] : null,
					'option2' => isset( $values[1] ) ? $values[1] : null,
					'option3' => isset( $values[2] ) ? $values[2] : null,
					'sku'     => $variation->get_sku() ? $variation->get_sku() : null,
				),
				$prices
			);
			$urbby_id = get_post_meta( $variation_id, self::META_VARIANT, true );
			if ( $urbby_id ) {
				$row['id'] = $urbby_id;
			} else {
				$row['stock'] = max( 0, (int) $variation->get_stock_quantity() );
			}
			$variants[] = $row;

			$cents = (int) str_replace( '.', '', $prices['price'] );
			if ( null === $min_cents || $cents < $min_cents ) {
				$min_cents = $cents;
				$min_price = $prices['price'];
			}
		}
		if ( ! $variants ) {
			return new WP_Error( 'variants', 'Este producto no tiene variaciones activas.' );
		}
		if ( count( $variants ) > self::MAX_VARIANTS ) {
			return new WP_Error( 'variants', 'Urbby admite hasta 12 variaciones por producto.' );
		}
		return array(
			'price'    => $min_price,
			'options'  => $options,
			'variants' => $variants,
		);
	}

	/**
	 * Precio vigente y, si está en oferta, el regular como «antes».
	 *
	 * @param WC_Product $product Producto o variación.
	 * @return array|WP_Error
	 */
	private static function prices( WC_Product $product ) {
		$price = Urbby_WC_Mapper::money( $product->get_price() );
		if ( null === $price ) {
			return new WP_Error( 'price', 'Ponele precio: Urbby no publica productos sin precio.' );
		}
		$regular = Urbby_WC_Mapper::money( $product->get_regular_price() );
		$before  = null;
		if ( $product->is_on_sale() && null !== $regular && (int) str_replace( '.', '', $regular ) > (int) str_replace( '.', '', $price ) ) {
			$before = $regular;
		}
		return array(
			'price'          => $price,
			'compareAtPrice' => $before,
		);
	}
}
