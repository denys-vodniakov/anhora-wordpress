<?php
/**
 * Frontend embed + page context bridge.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads a local injector for the Anhora SaaS widget and emits page context.
 */
class Anhora_Embed {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue local injector, host bridge, and boot payload.
	 */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		$settings = Anhora_Settings::get();
		if ( empty( $settings['embed_enabled'] ) || empty( $settings['deployment_key'] ) ) {
			return;
		}

		$loader_url = self::loader_url( $settings );
		if ( '' === $loader_url ) {
			return;
		}

		wp_enqueue_script(
			'anhora-embed-loader',
			ANHORA_PLUGIN_URL . 'assets/js/embed-loader.js',
			array(),
			ANHORA_VERSION,
			true
		);

		wp_localize_script(
			'anhora-embed-loader',
			'anhoraEmbed',
			array(
				'loaderUrl'     => $loader_url,
				'deploymentKey' => (string) $settings['deployment_key'],
				'apiBase'       => untrailingslashit( (string) $settings['api_base'] ),
			)
		);

		wp_enqueue_script(
			'anhora-host-bridge',
			ANHORA_PLUGIN_URL . 'assets/js/host-bridge.js',
			array(),
			ANHORA_VERSION,
			true
		);

		$bridge = array(
			'page' => array(
				'url'   => self::current_url(),
				'title' => wp_get_document_title(),
			),
		);

		/**
		 * Allow Woo module to attach user / orders / products.
		 *
		 * @param array<string,mixed> $bridge Bridge payload.
		 */
		$bridge = apply_filters( 'anhora_host_bridge_boot', $bridge );

		wp_add_inline_script(
			'anhora-host-bridge',
			'window.__ANHORA_HOST_BOOT__ = ' . wp_json_encode( $bridge ) . ';',
			'before'
		);
	}

	/**
	 * Official or merchant-overridden SaaS loader URL.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private static function loader_url( array $settings ): string {
		$custom = esc_url_raw( (string) $settings['loader_url'] );
		if ( '' !== $custom ) {
			return $custom;
		}

		return 'https://anhora.net/' . 'anhora-loader.js';
	}

	/**
	 * Current request URL.
	 */
	private static function current_url(): string {
		return home_url( add_query_arg( array() ) );
	}
}
