<?php
/**
 * WooCommerce catalog delta events and background snapshots.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

class Anhora_Woo_Catalog_Sync {

	public const CRON_HOOK          = 'anhora_cron_sync_catalog';
	public const PAGE_ACTION        = 'anhora_process_catalog_snapshot_page';
	public const NAMESPACE          = 'woocommerce.catalog';
	public const STATE_OPTION       = 'anhora_catalog_sync_state';
	public const LOCK_OPTION        = 'anhora_catalog_sync_lock';
	public const DEFAULT_BATCH_SIZE = 40;
	public const MAX_RETRIES        = 5;
	public const LOCK_TTL           = 300;
	public const WATCHDOG_DELAY     = 300;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_sync' ) );
		add_action( self::PAGE_ACTION, array( __CLASS__, 'process_snapshot_page' ), 10, 5 );
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

	/**
	 * Cancel queued catalog workers without deleting resumable progress.
	 */
	public static function clear_page_actions(): void {
		wp_clear_scheduled_hook( self::PAGE_ACTION );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::PAGE_ACTION, null, 'anhora' );
		}
		delete_option( self::LOCK_OPTION );
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
	 * @return array{ok:bool,queued?:bool,resumed?:bool,runId?:string,error?:string}
	 */
	public static function sync_full(): array {
		$state = self::state();
		if ( ! empty( $state['runId'] ) && in_array( $state['status'], array( 'queued', 'running', 'retrying', 'failed' ), true ) ) {
			$state['status']      = 'queued';
			$state['retryCount']  = 0;
			$state['lastError']   = '';
			$state['nextRetryAt'] = 0;
			$state['updatedAt']   = time();
			self::schedule_watchdog(
				(string) $state['runId'],
				(int) $state['cursorId'],
				(int) $state['uploadPage'],
				(int) $state['count'],
				0
			);
			self::save_state( $state );
			self::enqueue_page(
				(string) $state['runId'],
				(int) $state['cursorId'],
				(int) $state['uploadPage'],
				(int) $state['count'],
				0
			);
			return array(
				'ok'      => true,
				'queued'  => true,
				'resumed' => true,
				'runId'   => (string) $state['runId'],
			);
		}

		return self::start_snapshot( false );
	}

	/**
	 * Abort local/server progress and start a new full snapshot.
	 *
	 * @return array{ok:bool,queued?:bool,runId?:string,error?:string}
	 */
	public static function restart_full(): array {
		$state = self::state();
		$lock  = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $lock ) && (int) ( $lock['expiresAt'] ?? 0 ) > time() ) {
			return array(
				'ok'    => false,
				'error' => 'A catalog page is being processed. Retry the restart in a few minutes.',
			);
		}
		self::clear_page_actions();
		if ( ! empty( $state['runId'] ) ) {
			Anhora_Client::abort_snapshot( (string) $state['runId'] );
		}
		delete_option( self::STATE_OPTION );

		return self::start_snapshot( true );
	}

	/**
	 * Current resumable catalog state for the settings screen.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['runId'] ) ) {
			return array();
		}

		return array_merge(
			array(
				'runId'       => '',
				'status'      => 'queued',
				'cursorId'    => 0,
				'uploadPage'  => 0,
				'count'       => 0,
				'retryCount'  => 0,
				'lastError'   => '',
				'nextRetryAt' => 0,
				'startedAt'   => 0,
				'updatedAt'   => 0,
				'completedAt' => 0,
			),
			$state
		);
	}

	/**
	 * Start a new server snapshot, optionally replacing an unknown active run.
	 *
	 * @return array{ok:bool,queued?:bool,runId?:string,error?:string}
	 */
	private static function start_snapshot( bool $replace_active ): array {
		$begin = Anhora_Client::begin_snapshot( self::NAMESPACE, 'catalog_item' );
		if ( $replace_active && ! $begin['ok'] && 409 === (int) ( $begin['status'] ?? 0 ) ) {
			$active_run_id = self::active_run_id( self::error( $begin ) );
			if ( $active_run_id ) {
				Anhora_Client::abort_snapshot( $active_run_id );
				$begin = Anhora_Client::begin_snapshot( self::NAMESPACE, 'catalog_item' );
			}
		}
		if ( ! $begin['ok'] || empty( $begin['body']['runId'] ) ) {
			return array( 'ok' => false, 'error' => self::error( $begin ) );
		}
		$run_id = (string) $begin['body']['runId'];
		$state  = array(
			'runId'       => $run_id,
			'status'      => 'queued',
			'cursorId'    => 0,
			'uploadPage'  => 0,
			'count'       => 0,
			'retryCount'  => 0,
			'lastError'   => '',
			'nextRetryAt' => 0,
			'startedAt'   => time(),
			'updatedAt'   => time(),
			'completedAt' => 0,
		);
		self::schedule_watchdog( $run_id, 0, 0, 0, 0 );
		self::save_state( $state );
		self::enqueue_page( $run_id, 0, 0, 0, 0 );
		return array( 'ok' => true, 'queued' => true, 'runId' => $run_id );
	}

	/**
	 * Process one source page and schedule the next one.
	 */
	public static function process_snapshot_page( string $run_id, int $cursor_id, int $upload_page, int $count, int $attempt = 0 ): void {
		$state = self::state();
		if (
			empty( $state ) ||
			$run_id !== (string) $state['runId'] ||
			$cursor_id !== (int) $state['cursorId'] ||
			$upload_page !== (int) $state['uploadPage'] ||
			$count !== (int) $state['count'] ||
			$attempt !== (int) $state['retryCount'] ||
			'completed' === $state['status']
		) {
			return;
		}

		$lock_token = self::acquire_lock( $run_id );
		if ( ! $lock_token ) {
			return;
		}
		self::schedule_watchdog( $run_id, $cursor_id, $upload_page, $count, $attempt );

		try {
			$state['status']     = 'running';
			$state['updatedAt']  = time();
			$state['retryCount'] = $attempt;
			self::save_state( $state );

			$batch_size  = max( 1, min( 500, (int) apply_filters( 'anhora_catalog_batch_size', self::DEFAULT_BATCH_SIZE ) ) );
			$product_ids = self::next_product_ids( $cursor_id, $batch_size );
			$products    = $product_ids ? wc_get_products(
				array(
					'include' => array_map( 'intval', $product_ids ),
					'limit'   => $batch_size,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'objects',
				)
			) : array();
			$items       = array();
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
					self::retry_or_fail( $run_id, $cursor_id, $upload_page, $count, $attempt, self::error( $uploaded ) );
					return;
				}
				++$upload_page;
				$count += count( $items );
			}
			$next_cursor = $product_ids ? (int) end( $product_ids ) : $cursor_id;

			if ( count( $product_ids ) === $batch_size ) {
				self::schedule_watchdog( $run_id, $next_cursor, $upload_page, $count, 0 );
				self::save_state(
					array_merge(
						$state,
						array(
							'status'      => 'queued',
							'cursorId'    => $next_cursor,
							'uploadPage'  => $upload_page,
							'count'       => $count,
							'retryCount'  => 0,
							'lastError'   => '',
							'nextRetryAt' => 0,
							'updatedAt'   => time(),
						)
					)
				);
				self::enqueue_page( $run_id, $next_cursor, $upload_page, $count, 0 );
				return;
			}

			$commit = Anhora_Client::commit_snapshot( $run_id, $upload_page );
			if ( ! $commit['ok'] ) {
				self::retry_or_fail( $run_id, $cursor_id, (int) $state['uploadPage'], (int) $state['count'], $attempt, self::error( $commit ) );
				return;
			}
			$completed_at = time();
			self::save_state(
				array_merge(
					$state,
					array(
						'status'      => 'completed',
						'cursorId'    => $next_cursor,
						'uploadPage'  => $upload_page,
						'count'       => $count,
						'retryCount'  => 0,
						'lastError'   => '',
						'nextRetryAt' => 0,
						'updatedAt'   => $completed_at,
						'completedAt' => $completed_at,
					)
				)
			);
			update_option(
				'anhora_last_catalog_sync',
				array( 'runId' => $run_id, 'count' => $count, 'completedAt' => $completed_at ),
				false
			);
		} catch ( Throwable $error ) {
			self::retry_or_fail( $run_id, $cursor_id, (int) $state['uploadPage'], (int) $state['count'], $attempt, $error->getMessage() );
		} finally {
			self::release_lock( $lock_token );
		}
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

	private static function enqueue_page( string $run_id, int $cursor_id, int $upload_page, int $count, int $attempt, int $delay = 0 ): void {
		$args = array( $run_id, $cursor_id, $upload_page, $count, $attempt );
		if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::PAGE_ACTION, $args, 'anhora', true );
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::PAGE_ACTION, $args, 'anhora', true );
			return;
		}
		if ( ! wp_next_scheduled( self::PAGE_ACTION, $args ) ) {
			wp_schedule_single_event( time() + max( 5, $delay ), self::PAGE_ACTION, $args );
		}
	}

	private static function schedule_watchdog( string $run_id, int $cursor_id, int $upload_page, int $count, int $attempt, int $delay = 0 ): void {
		$args = array( $run_id, $cursor_id, $upload_page, $count, $attempt );
		if ( ! wp_next_scheduled( self::PAGE_ACTION, $args ) ) {
			wp_schedule_single_event( time() + max( self::WATCHDOG_DELAY, $delay ), self::PAGE_ACTION, $args );
		}
	}

	private static function retry_or_fail( string $run_id, int $cursor_id, int $upload_page, int $count, int $attempt, string $error ): void {
		$state        = self::state();
		if ( empty( $state ) || $run_id !== (string) $state['runId'] ) {
			return;
		}
		$next_attempt = $attempt + 1;
		$retry        = $next_attempt <= self::MAX_RETRIES;
		$delay        = min( 15 * MINUTE_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** $attempt ) );
		$state        = array_merge(
			$state,
			array(
				'runId'       => $run_id,
				'status'      => $retry ? 'retrying' : 'failed',
				'cursorId'    => $cursor_id,
				'uploadPage'  => $upload_page,
				'count'       => $count,
				'retryCount'  => $next_attempt,
				'lastError'   => sanitize_text_field( $error ),
				'nextRetryAt' => $retry ? time() + $delay : 0,
				'updatedAt'   => time(),
			)
		);
		if ( $retry ) {
			self::schedule_watchdog( $run_id, $cursor_id, $upload_page, $count, $next_attempt, $delay + MINUTE_IN_SECONDS );
		}
		self::save_state( $state );
		if ( $retry ) {
			self::enqueue_page( $run_id, $cursor_id, $upload_page, $count, $next_attempt, $delay );
		}
	}

	private static function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	private static function acquire_lock( string $run_id ): string {
		$token = wp_generate_uuid4();
		$lock  = array(
			'token'     => $token,
			'runId'     => $run_id,
			'expiresAt' => time() + self::LOCK_TTL,
		);
		if ( add_option( self::LOCK_OPTION, $lock, '', false ) ) {
			return $token;
		}

		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && (int) ( $current['expiresAt'] ?? 0 ) <= time() ) {
			delete_option( self::LOCK_OPTION );
			if ( add_option( self::LOCK_OPTION, $lock, '', false ) ) {
				return $token;
			}
		}
		return '';
	}

	private static function release_lock( string $token ): void {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && $token === (string) ( $current['token'] ?? '' ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	private static function active_run_id( string $error ): string {
		if ( preg_match( '/Snapshot\\s+([a-z0-9]{20,40})\\s+is already active/u', $error, $matches ) ) {
			return (string) $matches[1];
		}
		return '';
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
