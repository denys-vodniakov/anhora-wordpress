<?php
/**
 * Uninstall Anhora.
 *
 * @package Anhora
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'anhora_settings' );
delete_option( 'anhora_last_catalog_sync' );
delete_option( 'anhora_catalog_sync_state' );
delete_option( 'anhora_catalog_sync_lock' );
delete_option( 'anhora_catalog_contract_version' );
delete_option( 'anhora_catalog_contract_pending' );

$anhora_hooks = array( 'anhora_cron_sync_knowledge', 'anhora_cron_sync_catalog', 'anhora_process_catalog_snapshot_page', 'anhora_upgrade_sync_catalog' );
foreach ( $anhora_hooks as $anhora_hook ) {
	$anhora_timestamp = wp_next_scheduled( $anhora_hook );
	while ( $anhora_timestamp ) {
		wp_unschedule_event( $anhora_timestamp, $anhora_hook );
		$anhora_timestamp = wp_next_scheduled( $anhora_hook );
	}
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'anhora_process_catalog_snapshot_page', null, 'anhora' );
}
