<?php
/**
 * WooCommerce module bootstrap.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads Woo-specific sync + bridge when WooCommerce is active.
 */
class Anhora_Woo {

	/**
	 * Init Woo hooks.
	 */
	public static function init(): void {
		Anhora_Woo_Catalog_Sync::init();
		Anhora_Woo_Shipping_Knowledge::init();
		Anhora_Woo_Bridge::init();
	}
}
