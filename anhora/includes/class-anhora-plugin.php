<?php
/**
 * Plugin bootstrap.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
class Anhora_Plugin {

	/**
	 * Wire hooks.
	 */
	public function init(): void {
		load_plugin_textdomain( 'anhora', false, dirname( plugin_basename( ANHORA_PLUGIN_FILE ) ) . '/languages' );

		Anhora_Settings::init();
		Anhora_Embed::init();
		Anhora_Knowledge_Sync::init();

		if ( $this->is_woocommerce_active() ) {
			require_once ANHORA_PLUGIN_DIR . 'includes/woocommerce/class-anhora-woo-mapper.php';
			require_once ANHORA_PLUGIN_DIR . 'includes/woocommerce/class-anhora-woo-catalog-sync.php';
			require_once ANHORA_PLUGIN_DIR . 'includes/woocommerce/class-anhora-woo-shipping-knowledge.php';
			require_once ANHORA_PLUGIN_DIR . 'includes/woocommerce/class-anhora-woo-bridge.php';
			require_once ANHORA_PLUGIN_DIR . 'includes/woocommerce/class-anhora-woo.php';
			Anhora_Woo::init();
		}
	}

	/**
	 * Whether WooCommerce is available.
	 */
	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}
}
