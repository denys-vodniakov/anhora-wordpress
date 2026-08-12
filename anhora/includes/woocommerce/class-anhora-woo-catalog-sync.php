<?php
/**
 * WooCommerce catalog → Anhora ingest.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * On-save delta + nightly full replace (batched to avoid 413).
 */
class Anhora_Woo_Catalog_Sync {

	public const CRON_HOOK = 'anhora_cron_sync_catalog';

	/**
	 * Default products per ingest request.
	 * Keep well under typical nginx/API body limits (~1MB).
	 */
	public const DEFAULT_BATCH_SIZE = 40;

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
	 * Full catalog replace in batches.
	 *
	 * First batch sends replace.catalog=true (clears remote catalog), then
	 * remaining batches upsert without replace so we do not wipe prior chunks.
	 *
	 * @return array{ok:bool,count?:int,batches?:int,error?:string}
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

		/**
		 * Filter catalog ingest batch size (products per request).
		 *
		 * @param int $batch_size Default batch size.
		 */
		$batch_size = (int) apply_filters( 'anhora_catalog_batch_size', self::DEFAULT_BATCH_SIZE );
		if ( $batch_size < 1 ) {
			$batch_size = 1;
		}

		if ( empty( $items ) ) {
			// Still clear remote catalog when local shop is empty.
			$result = Anhora_Client::ingest(
				array(
					'widgetId' => (string) $settings['widget_id'],
					'catalog'  => array(),
					'replace'  => array( 'catalog' => true ),
				)
			);
			if ( ! $result['ok'] ) {
				return array(
					'ok'    => false,
					'error' => self::format_ingest_error( $result ),
				);
			}
			return array(
				'ok'      => true,
				'count'   => 0,
				'batches' => 1,
			);
		}

		$chunks  = array_chunk( $items, $batch_size );
		$batches = 0;

		foreach ( $chunks as $index => $chunk ) {
			$body = array(
				'widgetId' => (string) $settings['widget_id'],
				'catalog'  => $chunk,
			);
			// Only the first chunk replaces; later chunks upsert.
			if ( 0 === $index ) {
				$body['replace'] = array( 'catalog' => true );
			}

			$result = self::ingest_chunk_with_413_retry( $body );
			if ( ! $result['ok'] ) {
				return array(
					'ok'      => false,
					'count'   => $index * $batch_size,
					'batches' => $batches,
					'error'   => sprintf(
						/* translators: 1: batch number (1-based), 2: total batches, 3: error */
						__( 'batch %1$d/%2$d failed: %3$s', 'anhora' ),
						$index + 1,
						count( $chunks ),
						self::format_ingest_error( $result )
					),
				);
			}
			++$batches;
		}

		return array(
			'ok'      => true,
			'count'   => count( $items ),
			'batches' => $batches,
		);
	}

	/**
	 * POST one chunk; on HTTP 413 split in half and retry.
	 *
	 * @param array<string,mixed> $body Ingest body with catalog array.
	 * @return array{ok:bool,status:int,body?:mixed,error?:string}
	 */
	private static function ingest_chunk_with_413_retry( array $body ): array {
		$result = Anhora_Client::ingest( $body );
		if ( $result['ok'] ) {
			return $result;
		}

		$status  = (int) ( $result['status'] ?? 0 );
		$catalog = isset( $body['catalog'] ) && is_array( $body['catalog'] ) ? $body['catalog'] : array();
		$count   = count( $catalog );

		if ( 413 !== $status || $count <= 1 ) {
			return $result;
		}

		$mid   = (int) ceil( $count / 2 );
		$left  = array_slice( $catalog, 0, $mid );
		$right = array_slice( $catalog, $mid );

		$left_body            = $body;
		$left_body['catalog'] = $left;
		$left_result          = self::ingest_chunk_with_413_retry( $left_body );
		if ( ! $left_result['ok'] ) {
			return $left_result;
		}

		// After a successful left half that may have carried replace, right half must upsert only.
		$right_body = array(
			'widgetId' => $body['widgetId'],
			'catalog'  => $right,
		);
		return self::ingest_chunk_with_413_retry( $right_body );
	}

	/**
	 * Human-readable ingest error (prefer API JSON message).
	 *
	 * @param array{ok?:bool,status?:int,body?:mixed,error?:string} $result Client result.
	 */
	private static function format_ingest_error( array $result ): string {
		$body = $result['body'] ?? null;
		if ( is_array( $body ) && ! empty( $body['message'] ) ) {
			$status = (int) ( $result['status'] ?? 0 );
			$msg    = is_string( $body['message'] ) ? $body['message'] : wp_json_encode( $body['message'] );
			return $status ? sprintf( 'HTTP %d: %s', $status, $msg ) : $msg;
		}
		if ( ! empty( $result['error'] ) ) {
			return (string) $result['error'];
		}
		$status = (int) ( $result['status'] ?? 0 );
		return $status ? sprintf( 'HTTP %d', $status ) : 'ingest failed';
	}
}
