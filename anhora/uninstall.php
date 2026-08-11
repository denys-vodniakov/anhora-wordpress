<?php
/**
 * Uninstall Anhora.
 *
 * @package Anhora
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'anhora_settings' );

$hooks = array( 'anhora_cron_sync_knowledge', 'anhora_cron_sync_catalog' );
foreach ( $hooks as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
		$timestamp = wp_next_scheduled( $hook );
	}
}
