<?php
/**
 * Sync selected WordPress pages into canonical Anhora knowledge.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

class Anhora_Knowledge_Sync {

	public const CRON_HOOK = 'anhora_cron_sync_knowledge';
	public const NAMESPACE = 'wordpress.knowledge';

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_sync' ) );
		add_action( 'save_post_page', array( __CLASS__, 'maybe_sync_on_save' ), 20, 2 );
		add_action( 'save_post_post', array( __CLASS__, 'maybe_sync_on_save' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ), 20, 2 );
		self::schedule_cron();
	}

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function clear_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public static function cron_sync(): void {
		self::sync_selected_pages( true );
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function maybe_sync_on_save( int $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$settings = Anhora_Settings::get();
		if ( empty( $settings['sync_on_save'] ) ) {
			return;
		}
		$ids = array_map( 'intval', $settings['knowledge_page_ids'] );
		if ( ! in_array( $post_id, $ids, true ) ) {
			return;
		}

		$item = self::post_item( $post );
		if ( $item ) {
			Anhora_Client::events(
				self::NAMESPACE,
				array( self::upsert_event( $item, $post ) )
			);
		} else {
			self::send_delete( $post_id, (string) $post->post_modified_gmt );
		}
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_delete( int $post_id, $post ): void {
		$settings = Anhora_Settings::get();
		if ( ! in_array( $post_id, array_map( 'intval', $settings['knowledge_page_ids'] ), true ) ) {
			return;
		}
		self::send_delete( $post_id, gmdate( 'Y-m-d\TH:i:s\Z' ) );
	}

	/**
	 * @return array{ok:bool,count?:int,error?:string}
	 */
	public static function sync_selected_pages( bool $replace = true ): array {
		$settings = Anhora_Settings::get();
		return self::sync_posts(
			array_map( 'intval', $settings['knowledge_page_ids'] ),
			$replace
		);
	}

	/**
	 * @param int[] $post_ids Post IDs.
	 * @return array{ok:bool,count?:int,error?:string}
	 */
	public static function sync_posts( array $post_ids, bool $replace ): array {
		$items = array();
		$posts = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			$item = $post ? self::post_item( $post ) : null;
			if ( $item ) {
				$items[] = $item;
				$posts[] = $post;
			}
		}

		if ( ! $replace ) {
			if ( ! $items ) {
				return array( 'ok' => true, 'count' => 0 );
			}
			$events = array();
			foreach ( $items as $index => $item ) {
				$events[] = self::upsert_event( $item, $posts[ $index ] );
			}
			$result = Anhora_Client::events( self::NAMESPACE, $events );
			return $result['ok']
				? array( 'ok' => true, 'count' => count( $items ) )
				: array( 'ok' => false, 'error' => self::error( $result ) );
		}

		$begin = Anhora_Client::begin_snapshot( self::NAMESPACE, 'knowledge_document' );
		if ( ! $begin['ok'] || empty( $begin['body']['runId'] ) ) {
			return array( 'ok' => false, 'error' => self::error( $begin ) );
		}
		$run_id = (string) $begin['body']['runId'];
		$pages  = array_chunk( $items, 500 );
		foreach ( $pages as $page => $chunk ) {
			$result = Anhora_Client::snapshot_page( $run_id, $page, $chunk );
			if ( ! $result['ok'] ) {
				Anhora_Client::abort_snapshot( $run_id );
				return array( 'ok' => false, 'error' => self::error( $result ) );
			}
		}
		$commit = Anhora_Client::commit_snapshot( $run_id, count( $pages ) );
		return $commit['ok']
			? array( 'ok' => true, 'count' => count( $items ) )
			: array( 'ok' => false, 'error' => self::error( $commit ) );
	}

	/**
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>|null
	 */
	private static function post_item( $post ): ?array {
		if ( 'publish' !== $post->post_status ) {
			return null;
		}
		$content = self::plain_content( $post );
		if ( '' === $content ) {
			return null;
		}
		$settings = Anhora_Settings::get();
		$geo      = trim( (string) $settings['knowledge_geo_tag'] );
		if ( '' !== $geo ) {
			$content = 'Country: ' . $geo . "\n" . $content;
		}
		return array(
			'externalId'     => 'page:' . $post->ID,
			'sourceUpdatedAt' => self::modified_at( $post ),
			'payload'        => array(
				'title'   => $post->post_title,
				'content' => $content,
				'url'     => get_permalink( $post ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $item Item.
	 * @param WP_Post             $post Post.
	 * @return array<string,mixed>
	 */
	private static function upsert_event( array $item, $post ): array {
		return array(
			'eventId'        => 'wordpress:page:' . $post->ID . ':' . md5( (string) $post->post_modified_gmt ),
			'entityType'     => 'knowledge_document',
			'externalId'     => $item['externalId'],
			'operation'      => 'upsert',
			'sourceUpdatedAt' => $item['sourceUpdatedAt'],
			'payload'        => $item['payload'],
		);
	}

	private static function send_delete( int $post_id, string $modified ): void {
		Anhora_Client::events(
			self::NAMESPACE,
			array(
				array(
					'eventId'        => 'wordpress:page:delete:' . $post_id . ':' . md5( $modified ),
					'entityType'     => 'knowledge_document',
					'externalId'     => 'page:' . $post_id,
					'operation'      => 'delete',
					'sourceUpdatedAt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				),
			)
		);
	}

	/** @param WP_Post $post Post. */
	private static function modified_at( $post ): string {
		$time = $post->post_modified_gmt ?: get_gmt_from_date( $post->post_modified );
		return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $time . ' UTC' ) );
	}

	/** @param WP_Post $post Post. */
	private static function plain_content( $post ): string {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core content filter.
		$html = apply_filters( 'the_content', $post->post_content );
		$text = wp_strip_all_tags( $html );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		return trim( (string) $text );
	}

	/** @param array<string,mixed> $result Result. */
	private static function error( array $result ): string {
		if ( is_array( $result['body'] ?? null ) && ! empty( $result['body']['message'] ) ) {
			return is_string( $result['body']['message'] ) ? $result['body']['message'] : wp_json_encode( $result['body']['message'] );
		}
		return (string) ( $result['error'] ?? 'Anhora request failed' );
	}
}
