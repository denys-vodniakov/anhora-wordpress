<?php
/**
 * Uninstall Anhora.
 *
 * @package Anhora
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'anhora_settings' );
delete_option( 'anhora_last_catalog_sync' );

$hooks = array( 'anhora_cron_sync_knowledge', 'anhora_cron_sync_catalog', 'anhora_process_catalog_snapshot_page' );
foreach ( $hooks as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
		$timestamp = wp_next_scheduled( $hook );
	}
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'anhora_process_catalog_snapshot_page', null, 'anhora' );
}
