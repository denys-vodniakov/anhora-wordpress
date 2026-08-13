<?php
/**
 * WooCommerce catalog delta events and background snapshots.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

class Anhora_Woo_Catalog_Sync {

	public const CRON_HOOK       = 'anhora_cron_sync_catalog';
	public const PAGE_ACTION     = 'anhora_process_catalog_snapshot_page';
	public const NAMESPACE       = 'woocommerce.catalog';
	public const DEFAULT_BATCH_SIZE = 40;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_sync' ) );
		add_action( self::PAGE_ACTION, array( __CLASS__, 'process_snapshot_page' ), 10, 4 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_save' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_save' ), 20, 1 );
		add_action( 'woocommerce_before_delete_product', array( __CLASS__, 'on_product_delete' ), 20, 1 );
		self::schedule_cron();
	}

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', self::CRON_HOOK );
		}
	}

	public static function clear_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public static function cron_sync(): void {
		self::sync_full();
		Anhora_Woo_Shipping_Knowledge::sync();
	}

	public static function on_product_save( int $product_id ): void {
		$settings = Anhora_Settings::get();
		if ( empty( $settings['sync_on_save'] ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		$item    = Anhora_Woo_Mapper::to_ingest_item( $product );
		if ( ! $item ) {
			self::on_product_delete( $product_id );
			return;
		}
		$modified = $product->get_date_modified();
		$timestamp = $modified ? $modified->getTimestamp() : time();
		Anhora_Client::events(
			self::NAMESPACE,
			array(
				array(
					'eventId'        => 'woocommerce:product:' . $product_id . ':' . $timestamp,
					'entityType'     => 'catalog_item',
					'externalId'     => (string) $item['externalId'],
					'operation'      => 'upsert',
					'sourceSequence' => $timestamp,
					'sourceUpdatedAt' => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
					'payload'        => array(
						'name'    => $item['name'],
						'product' => $item['product'],
					),
				),
			)
		);
	}

	public static function on_product_delete( int $product_id ): void {
		$timestamp = time();
		Anhora_Client::events(
			self::NAMESPACE,
			array(
				array(
					'eventId'        => 'woocommerce:product:delete:' . $product_id . ':' . $timestamp,
					'entityType'     => 'catalog_item',
					'externalId'     => Anhora_Woo_Mapper::external_id( $product_id ),
					'operation'      => 'delete',
					'sourceSequence' => $timestamp,
					'sourceUpdatedAt' => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
				),
			)
		);
	}

	/**
	 * Begin a background, resumable full snapshot.
	 *
	 * @return array{ok:bool,queued?:bool,runId?:string,error?:string}
	 */
	public static function sync_full(): array {
		$begin = Anhora_Client::begin_snapshot( self::NAMESPACE, 'catalog_item' );
		if ( ! $begin['ok'] || empty( $begin['body']['runId'] ) ) {
			return array( 'ok' => false, 'error' => self::error( $begin ) );
		}
		$run_id = (string) $begin['body']['runId'];
		self::enqueue_page( $run_id, 0, 0, 0 );
		return array( 'ok' => true, 'queued' => true, 'runId' => $run_id );
	}

	/**
	 * Process one source page and schedule the next one.
	 */
	public static function process_snapshot_page( string $run_id, int $cursor_id, int $upload_page, int $count ): void {
		$batch_size  = max( 1, min( 500, (int) apply_filters( 'anhora_catalog_batch_size', self::DEFAULT_BATCH_SIZE ) ) );
		$product_ids = self::next_product_ids( $cursor_id, $batch_size );
		$products = $product_ids ? wc_get_products(
			array(
				'include' => array_map( 'intval', $product_ids ),
				'limit'   => $batch_size,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		) : array();
		$items     = array();
		foreach ( $products as $product ) {
			$item = Anhora_Woo_Mapper::to_ingest_item( $product );
			if ( ! $item ) {
				continue;
			}
			$modified  = $product->get_date_modified();
			$timestamp = $modified ? $modified->getTimestamp() : time();
			$items[]   = array(
				'externalId'     => (string) $item['externalId'],
				'sourceSequence' => $timestamp,
				'sourceUpdatedAt' => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
				'payload'        => array(
					'name'    => $item['name'],
					'product' => $item['product'],
				),
			);
		}

		if ( $items ) {
			$uploaded = Anhora_Client::snapshot_page( $run_id, $upload_page, $items );
			if ( ! $uploaded['ok'] ) {
				throw new RuntimeException( self::error( $uploaded ) );
			}
			++$upload_page;
			$count += count( $items );
		}

		if ( count( $product_ids ) === $batch_size ) {
			self::enqueue_page( $run_id, (int) end( $product_ids ), $upload_page, $count );
			return;
		}

		$commit = Anhora_Client::commit_snapshot( $run_id, $upload_page );
		if ( ! $commit['ok'] ) {
			throw new RuntimeException( self::error( $commit ) );
		}
		update_option(
			'anhora_last_catalog_sync',
			array( 'runId' => $run_id, 'count' => $count, 'completedAt' => time() ),
			false
		);
	}

	/**
	 * Next published product IDs after a keyset cursor.
	 *
	 * @return int[]
	 */
	private static function next_product_ids( int $cursor_id, int $batch_size ): array {
		$catalog_where = static function ( $where, $query ) use ( $cursor_id ) {
			global $wpdb;
			if ( empty( $query->query['anhora_catalog_keyset'] ) ) {
				return $where;
			}
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $cursor_id );
		};

		add_filter( 'posts_where', $catalog_where, 10, 2 );
		$product_ids = get_posts(
			array(
				'post_type'             => 'product',
				'post_status'           => 'publish',
				'posts_per_page'        => $batch_size,
				'orderby'               => 'ID',
				'order'                 => 'ASC',
				'fields'                => 'ids',
				'no_found_rows'         => true,
				'suppress_filters'      => false,
				'anhora_catalog_keyset' => true,
			)
		);
		remove_filter( 'posts_where', $catalog_where, 10 );

		return array_map( 'intval', $product_ids );
	}

	private static function enqueue_page( string $run_id, int $cursor_id, int $upload_page, int $count ): void {
		$args = array( $run_id, $cursor_id, $upload_page, $count );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::PAGE_ACTION, $args, 'anhora' );
			return;
		}
		wp_schedule_single_event( time() + 5, self::PAGE_ACTION, $args );
	}

	/** @param array<string,mixed> $result Result. */
	private static function error( array $result ): string {
		$body = $result['body'] ?? null;
		if ( is_array( $body ) && ! empty( $body['message'] ) ) {
			return is_string( $body['message'] ) ? $body['message'] : wp_json_encode( $body['message'] );
		}
		return (string) ( $result['error'] ?? 'Anhora request failed' );
	}
}
