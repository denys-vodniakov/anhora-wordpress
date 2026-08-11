<?php
/**
 * Sync selected WP pages into Anhora knowledge.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge push (Phase 1).
 */
class Anhora_Knowledge_Sync {

	public const CRON_HOOK = 'anhora_cron_sync_knowledge';
	public const PREFIX    = 'wordpress:';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_sync' ) );
		add_action( 'save_post_page', array( __CLASS__, 'maybe_sync_on_save' ), 20, 2 );
		add_action( 'save_post_post', array( __CLASS__, 'maybe_sync_on_save' ), 20, 2 );
		self::schedule_cron();
	}

	/**
	 * Nightly cron.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
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
	 * Cron callback.
	 */
	public static function cron_sync(): void {
		self::sync_selected_pages( true );
	}

	/**
	 * On-save delta for selected pages.
	 *
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
		self::sync_posts( array( $post_id ), false );
	}

	/**
	 * Full sync of configured pages with replace by prefix.
	 *
	 * @param bool $replace Whether to drop stale wordpress:* rows.
	 * @return array{ok:bool,count?:int,error?:string}
	 */
	public static function sync_selected_pages( bool $replace = true ): array {
		$settings = Anhora_Settings::get();
		$ids      = array_map( 'intval', $settings['knowledge_page_ids'] );
		return self::sync_posts( $ids, $replace );
	}

	/**
	 * Build knowledge items and ingest.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @param bool  $replace  Replace wordpress:* knowledge not in batch.
	 * @return array{ok:bool,count?:int,error?:string}
	 */
	public static function sync_posts( array $post_ids, bool $replace ): array {
		$settings = Anhora_Settings::get();
		$geo      = trim( (string) $settings['knowledge_geo_tag'] );
		$items    = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$content = self::plain_content( $post );
			if ( '' === $content ) {
				continue;
			}
			if ( '' !== $geo ) {
				$content = 'Country: ' . $geo . "\n" . $content;
			}
			$items[] = array(
				'externalId' => self::PREFIX . 'page:' . $post_id,
				'name'       => $post->post_title,
				'content'    => $content,
				'url'        => get_permalink( $post ),
			);
		}

		$body = array(
			'widgetId'  => (string) $settings['widget_id'],
			'knowledge' => $items,
		);
		if ( $replace ) {
			$body['replace'] = array(
				'knowledge'                 => true,
				'knowledgeExternalIdPrefix' => self::PREFIX . 'page:',
			);
		}

		$result = Anhora_Client::ingest( $body );
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

	/**
	 * Strip HTML to plaintext for the prompt.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function plain_content( $post ): string {
		$html = apply_filters( 'the_content', $post->post_content );
		$text = wp_strip_all_tags( $html );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		return trim( (string) $text );
	}
}
