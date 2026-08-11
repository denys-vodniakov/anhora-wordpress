<?php
/**
 * WooCommerce catalog → Anhora ingest.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * On-save delta + nightly full replace.
 */
class Anhora_Woo_Catalog_Sync {

	public const CRON_HOOK = 'anhora_cron_sync_catalog';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_sync' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_save' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_save' ), 20, 1 );
		self::schedule_cron();
	}

	/**
	 * Schedule nightly full sync.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear cron.
	 */
	public static function clear_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Cron: full catalog + shipping knowledge.
	 */
	public static function cron_sync(): void {
		self::sync_full();
		Anhora_Woo_Shipping_Knowledge::sync();
	}

	/**
	 * Delta upsert one product.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function on_product_save( int $product_id ): void {
		$settings = Anhora_Settings::get();
		if ( empty( $settings['sync_on_save'] ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		$item    = Anhora_Woo_Mapper::to_ingest_item( $product );
		if ( ! $item ) {
			return;
		}
		Anhora_Client::ingest(
			array(
				'widgetId' => (string) $settings['widget_id'],
				'catalog'  => array( $item ),
			)
		);
	}

	/**
	 * Full catalog replace.
	 *
	 * @return array{ok:bool,count?:int,error?:string}
	 */
	public static function sync_full(): array {
		$settings = Anhora_Settings::get();
		$ids      = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'ids',
				'type'   => array( 'simple', 'variable', 'external', 'grouped' ),
			)
		);

		$items = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			// Skip pure variations as top-level; variable parent carries variants.
			if ( $product && $product->is_type( 'variation' ) ) {
				continue;
			}
			$item = Anhora_Woo_Mapper::to_ingest_item( $product );
			if ( $item ) {
				$items[] = $item;
			}
		}

		$result = Anhora_Client::ingest(
			array(
				'widgetId' => (string) $settings['widget_id'],
				'catalog'  => $items,
				'replace'  => array( 'catalog' => true ),
			)
		);

		if ( ! $result['ok'] ) {
			return array(
				'ok'    => false,
				'error' => (string) ( $result['error'] ?? 'ingest failed' ),
			);
		}

		return array(
			'ok'    => true,
			'count' => count( $items ),
		);
	}
}
