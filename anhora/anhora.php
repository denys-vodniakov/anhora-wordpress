<?php
/**
 * Plugin Name:       Anhora
 * Plugin URI:        https://anhora.net/integrate#wordpress
 * Description:       Embed the Anhora assistant, sync WordPress pages into knowledge, and connect WooCommerce catalog + session context.
 * Version:           0.2.4
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Anhora
 * Author URI:        https://anhora.net
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       anhora
 * Domain Path:       /languages
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

define( 'ANHORA_VERSION', '0.2.4' );
define( 'ANHORA_PLUGIN_FILE', __FILE__ );
define( 'ANHORA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANHORA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ANHORA_PLUGIN_DIR . 'includes/class-anhora-client.php';
require_once ANHORA_PLUGIN_DIR . 'includes/class-anhora-settings.php';
require_once ANHORA_PLUGIN_DIR . 'includes/class-anhora-embed.php';
require_once ANHORA_PLUGIN_DIR . 'includes/class-anhora-knowledge-sync.php';
require_once ANHORA_PLUGIN_DIR . 'includes/class-anhora-plugin.php';

/**
 * Bootstrap.
 */
function anhora_plugin(): Anhora_Plugin {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new Anhora_Plugin();
	}
	return $instance;
}

add_action(
	'plugins_loaded',
	static function () {
		anhora_plugin()->init();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		Anhora_Knowledge_Sync::schedule_cron();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'anhora_cron_sync_knowledge' );
		wp_clear_scheduled_hook( 'anhora_cron_sync_catalog' );
		wp_clear_scheduled_hook( 'anhora_process_catalog_snapshot_page' );
	}
);
