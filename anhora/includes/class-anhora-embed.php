<?php
/**
 * Frontend embed + page context bridge.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads anhora-loader.js and emits page context.
 */
class Anhora_Embed {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue loader and bridge helpers.
	 */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		$settings = Anhora_Settings::get();
		if ( empty( $settings['embed_enabled'] ) || empty( $settings['deployment_key'] ) ) {
			return;
		}

		$loader = (string) $settings['loader_url'];
		wp_enqueue_script(
			'anhora-loader',
			$loader,
			array(),
			ANHORA_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_script_add_data( 'anhora-loader', 'data-anhora-deployment-key', (string) $settings['deployment_key'] );

		// data-* attributes via filter (WP core does not map add_data to HTML attrs reliably for all versions).
		add_filter(
			'script_loader_tag',
			static function ( $tag, $handle ) use ( $settings ) {
				if ( 'anhora-loader' !== $handle ) {
					return $tag;
				}
				$attrs = sprintf(
					' data-anhora-deployment-key="%s" data-anhora-api-base="%s" data-anhora-widget-channel="stable"',
					esc_attr( (string) $settings['deployment_key'] ),
					esc_attr( untrailingslashit( (string) $settings['api_base'] ) )
				);
				return str_replace( ' src=', $attrs . ' src=', $tag );
			},
			10,
			2
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
	 * Current request URL.
	 */
	private static function current_url(): string {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return home_url( add_query_arg( array() ) );
		}
		$scheme = is_ssl() ? 'https' : 'http';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return $scheme . '://' . wp_unslash( $_SERVER['HTTP_HOST'] ) . $uri;
	}
}
