<?php
/**
 * Map WooCommerce products to Anhora Product JSON.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stable Woo → Anhora Product mapper.
 */
class Anhora_Woo_Mapper {

	public const PLATFORM = 'woocommerce';

	/**
	 * externalId for a product or variation.
	 *
	 * @param int      $product_id   Product ID.
	 * @param int|null $variation_id Variation ID.
	 */
	public static function external_id( int $product_id, ?int $variation_id = null ): string {
		if ( $variation_id ) {
			return self::PLATFORM . ':' . $product_id . ':' . $variation_id;
		}
		return self::PLATFORM . ':' . $product_id;
	}

	/**
	 * Build ingest catalog item from WC product.
	 *
	 * @param WC_Product $product Product.
	 * @return array{externalId:string,name:string,product:array<string,mixed>}|null
	 */
	public static function to_ingest_item( $product ): ?array {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return null;
		}
		if ( 'publish' !== $product->get_status() ) {
			return null;
		}

		$id   = (string) $product->get_id();
		$name = $product->get_name();
		$desc = self::description_text( $product );
		$img  = self::preview_image( $product );
		$url  = $product->get_permalink();

		$variants = array();
		if ( $product->is_type( 'variable' ) ) {
			/** @var WC_Product_Variable $product */
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation || 'publish' !== $variation->get_status() ) {
					continue;
				}
				$variants[] = self::map_variant( $variation, $name );
			}
			if ( ! $variants ) {
				return null;
			}
		} else {
			$variants[] = self::map_variant( $product, $name );
		}

		$anhora_product = array(
			'id'       => $id,
			'family'   => array(
				'id'   => $id,
				'name' => $name,
			),
			'details'  => array(
				'shortDescription' => $desc,
				'previewImageUrl'  => $img,
			),
			'variants' => $variants,
			'catalog'  => self::catalog_metadata( $product ),
		);
		if ( $url ) {
			$anhora_product['url'] = esc_url_raw( (string) $url );
		}

		return array(
			'externalId' => self::external_id( (int) $product->get_id() ),
			'name'       => $name,
			'product'    => $anhora_product,
		);
	}

	/**
	 * Normalized discovery metadata used by category and attribute search.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string,mixed>
	 */
	private static function catalog_metadata( $product ): array {
		$categories = wp_get_post_terms(
			$product->get_id(),
			'product_cat',
			array( 'fields' => 'all' )
		);
		$tags = wp_get_post_terms(
			$product->get_id(),
			'product_tag',
			array( 'fields' => 'names' )
		);
		$category_names = array();
		$category_ids   = array();
		if ( ! is_wp_error( $categories ) ) {
			foreach ( $categories as $category ) {
				$category_name = self::clean_text( $category->name );
				if ( '' !== $category_name ) {
					$category_names[] = $category_name;
				}
				$category_ids[]   = (string) $category->term_id;
			}
		}

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			$name = self::clean_text( wc_attribute_label( $attribute->get_name(), $product ) );
			if ( ! $name ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms(
					$product->get_id(),
					$attribute->get_name(),
					array( 'fields' => 'names' )
				);
			} else {
				$values = $attribute->get_options();
			}
			if ( ! is_wp_error( $values ) ) {
				$clean = array_values(
					array_filter(
						array_map(
							static fn( $value ): string => self::clean_text( $value ),
							(array) $values
						),
						static fn( string $value ): bool => '' !== trim( $value )
					)
				);
				if ( $clean ) {
					$attributes[ (string) $name ] = $clean;
				}
			}
		}

		return array(
			'categories'    => array_values( array_unique( $category_names ) ),
			'categoryIds'   => array_values( array_unique( $category_ids ) ),
			'tags'          => is_wp_error( $tags ) ? array() : array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( $value ): string => self::clean_text( $value ),
							$tags
						)
					)
				)
			),
			'productType'   => (string) $product->get_type(),
			'attributes'    => $attributes,
			'merchandising' => array(
				'featured'     => (bool) $product->is_featured(),
				'totalSales'   => (int) $product->get_total_sales(),
				'averageRating' => (float) $product->get_average_rating(),
				'reviewCount'  => (int) $product->get_review_count(),
				'menuOrder'    => (int) $product->get_menu_order(),
			),
		);
	}

	/**
	 * Map one purchasable unit to ProductVariant.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param string     $fallback_name Parent name.
	 * @return array<string,mixed>
	 */
	private static function map_variant( $product, string $fallback_name ): array {
		$price    = (float) $product->get_price();
		$currency = get_woocommerce_currency();
		$packaging = $product->get_name();
		if ( $product->is_type( 'variation' ) ) {
			$packaging = wc_get_formatted_variation( $product, true, false, false );
			if ( '' === trim( wp_strip_all_tags( (string) $packaging ) ) ) {
				$packaging = $fallback_name;
			}
		}

		return array(
			'id'           => (string) $product->get_id(),
			'packaging'    => wp_strip_all_tags( (string) $packaging ),
			'sku'          => (string) $product->get_sku(),
			'priceInfo'    => array(
				'prices'          => array(
					'Retail' => $price,
				),
				'qualityValue'    => 0,
				'commissionValue' => 0,
				'tax'             => 0,
				'currency'        => $currency,
			),
			'availability' => array(
				'inStock'  => $product->is_in_stock(),
				'quantity' => (int) $product->get_stock_quantity(),
			),
			'details'      => array(
				'shortDescription' => self::description_text( $product, $fallback_name ),
			),
		);
	}

	/**
	 * Keep every customer-visible WooCommerce description in the catalog
	 * contract. Stores commonly use the short description for a specification
	 * table and the full description for searchable product characteristics.
	 *
	 * @param WC_Product $product      Product or variation.
	 * @param string     $fallback_text Text used only when both descriptions are empty.
	 */
	private static function description_text( $product, string $fallback_text = '' ): string {
		$parts = array();
		foreach ( array( $product->get_short_description(), $product->get_description() ) as $value ) {
			$clean = self::clean_text( $value );
			if ( '' !== $clean && ! in_array( $clean, $parts, true ) ) {
				$parts[] = $clean;
			}
		}

		if ( ! $parts && '' !== trim( $fallback_text ) ) {
			$parts[] = self::clean_text( $fallback_text );
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Convert WooCommerce HTML into stable multilingual search text.
	 *
	 * @param mixed $value HTML or plain text.
	 */
	private static function clean_text( $value ): string {
		$source = (string) $value;
		$source = preg_replace( '/<br\b[^>]*>/iu', "\n", $source ) ?? $source;
		$source = preg_replace( '/<\/(?:p|div|li|tr|td|th|h[1-6])\s*>/iu', "\n", $source ) ?? $source;
		$clean = html_entity_decode(
			wp_strip_all_tags( $source ),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);
		$clean = str_replace( array( "\r\n", "\r", "\xc2\xa0" ), array( "\n", "\n", ' ' ), $clean );
		$clean = preg_replace( '/[ \t]+/u', ' ', $clean ) ?? $clean;
		$clean = preg_replace( '/ *\n */u', "\n", $clean ) ?? $clean;
		$clean = preg_replace( '/\n{3,}/u', "\n\n", $clean ) ?? $clean;
		return trim( $clean );
	}

	/**
	 * Contentful-like preview image shape required by Anhora Product.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string,mixed>
	 */
	private static function preview_image( $product ): array {
		$image_id = (int) $product->get_image_id();
		$url      = $image_id ? wp_get_attachment_url( $image_id ) : wc_placeholder_img_src( 'woocommerce_single' );
		$url      = $url ? set_url_scheme( $url, 'https' ) : '';
		$meta     = $image_id ? wp_get_attachment_metadata( $image_id ) : array();
		$width    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height   = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$file     = $image_id ? basename( (string) get_attached_file( $image_id ) ) : 'placeholder.jpg';
		$mime     = $image_id ? (string) get_post_mime_type( $image_id ) : 'image/jpeg';

		return array(
			'fields' => array(
				'title' => $product->get_name(),
				'file'  => array(
					'url'         => $url,
					'fileName'    => $file,
					'contentType' => $mime ? $mime : 'image/jpeg',
					'details'     => array(
						'size'  => 0,
						'image' => array(
							'width'  => $width,
							'height' => $height,
						),
					),
				),
			),
		);
	}
}
