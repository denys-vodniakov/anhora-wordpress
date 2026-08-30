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
		add_shortcode( 'anhora_chat_button', array( __CLASS__, 'render_chat_button' ) );
	}

	/**
	 * Render a theme-styleable button that opens the mounted Anhora chat.
	 * The universal bundle removes `hidden` only when external Commerce
	 * triggers are enabled by the runtime config.
	 *
	 * @param array<string,mixed> $attributes Shortcode attributes.
	 * @param string|null         $content Enclosed button label.
	 */
	public static function render_chat_button( $attributes = array(), ?string $content = null ): string {
		$settings = Anhora_Settings::get();
		if ( empty( $settings['embed_enabled'] ) || empty( $settings['deployment_key'] ) ) {
			return '';
		}
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}

		$attributes = shortcode_atts(
			array(
				'text'       => '',
				'text_en'    => '',
				'text_ru'    => '',
				'text_uk'    => '',
				'text_he'    => '',
				'text_de'    => '',
				'text_fr'    => '',
				'text_es'    => '',
				'text_it'    => '',
				'text_pl'    => '',
				'text_nl'    => '',
				'text_pt'    => '',
				'aria_label' => '',
				'class'      => '',
			),
			$attributes,
			'anhora_chat_button'
		);
		$label = self::localized_button_label( $attributes, $content );
		$accessible_label = '' !== trim( (string) $attributes['aria_label'] )
			? sanitize_text_field( (string) $attributes['aria_label'] )
			: $label;
		$custom_classes = preg_split( '/\s+/', (string) $attributes['class'] ) ?: array();
		$classes = array_merge(
			array( 'anhora-chat-open', 'anhora-chat-shortcode' ),
			array_filter( array_map( 'sanitize_html_class', $custom_classes ) )
		);

		return sprintf(
			'<button type="button" class="%1$s" data-anhora-chat-open data-anhora-chat-shortcode aria-label="%3$s" aria-haspopup="dialog" aria-expanded="false" hidden>%2$s</button>',
			esc_attr( implode( ' ', array_unique( $classes ) ) ),
			esc_html( $label ),
			esc_attr( $accessible_label )
		);
	}

	/**
	 * Resolve explicit, locale-specific, or built-in shortcode text.
	 *
	 * @param array<string,mixed> $attributes Shortcode attributes.
	 * @param string|null         $content Enclosed button label.
	 */
	private static function localized_button_label( array $attributes, ?string $content ): string {
		if ( null !== $content && '' !== trim( $content ) ) {
			return wp_strip_all_tags( $content );
		}
		if ( '' !== trim( (string) $attributes['text'] ) ) {
			return sanitize_text_field( (string) $attributes['text'] );
		}

		$locale   = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$language = strtolower( strtok( str_replace( '-', '_', $locale ), '_' ) ?: 'en' );
		$key      = 'text_' . $language;
		if ( isset( $attributes[ $key ] ) && '' !== trim( (string) $attributes[ $key ] ) ) {
			return sanitize_text_field( (string) $attributes[ $key ] );
		}

		$defaults = array(
			'de' => 'Chat öffnen',
			'es' => 'Abrir chat',
			'fr' => 'Ouvrir le chat',
			'he' => 'דברו איתנו',
			'it' => 'Apri la chat',
			'nl' => 'Chat openen',
			'pl' => 'Otwórz czat',
			'pt' => 'Abrir o chat',
			'ru' => 'Написать нам',
			'uk' => 'Написати нам',
		);
		return $defaults[ $language ] ?? __( 'Chat with us', 'anhora' );
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
			'adapter' => array(
				'name'         => 'wordpress',
				'version'      => ANHORA_VERSION,
				'protocol'     => 1,
					'capabilities' => class_exists( 'WooCommerce' )
						? array( 'page_context', 'catalog_context', 'order_context', 'logout_cleanup' )
						: array( 'page_context', 'logout_cleanup' ),
			),
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
