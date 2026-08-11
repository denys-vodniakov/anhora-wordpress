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
		$desc = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
		$img  = self::preview_image( $product );

		$variants = array();
		if ( $product->is_type( 'variable' ) ) {
			/** @var WC_Product_Variable $product */
			foreach ( $product->get_available_variations() as $variation_data ) {
				$variation = wc_get_product( $variation_data['variation_id'] );
				if ( ! $variation ) {
					continue;
				}
				$variants[] = self::map_variant( $variation, $name );
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
		);

		return array(
			'externalId' => self::external_id( (int) $product->get_id() ),
			'name'       => $name,
			'product'    => $anhora_product,
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
				'shortDescription' => wp_strip_all_tags( $product->get_short_description() ?: $fallback_name ),
			),
		);
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
