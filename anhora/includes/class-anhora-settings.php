<?php
/**
 * Plugin settings (WP Admin).
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page and option helpers.
 */
class Anhora_Settings {

	public const OPTION_KEY = 'anhora_settings';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_anhora_sync_knowledge', array( __CLASS__, 'handle_sync_knowledge' ) );
		add_action( 'admin_post_anhora_sync_catalog', array( __CLASS__, 'handle_sync_catalog' ) );
	}

	/**
	 * Default option values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_base'           => 'https://api.anhora.net/api',
			'widget_id'          => '',
			'installation_id'    => '',
			'ingest_secret'      => '',
			'deployment_key'     => '',
			'loader_url'         => 'https://anhora.net/anhora-loader.js',
			'embed_enabled'      => 1,
			'knowledge_page_ids' => array(),
			'knowledge_geo_tag'  => '',
			'sync_on_save'       => 1,
		);
	}

	/**
	 * Merged settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged = array_merge( self::defaults(), $stored );
		if ( ! is_array( $merged['knowledge_page_ids'] ) ) {
			$merged['knowledge_page_ids'] = array();
		}
		return $merged;
	}

	/**
	 * Admin menu.
	 */
	public static function register_menu(): void {
		add_options_page(
			__( 'Anhora', 'anhora' ),
			__( 'Anhora', 'anhora' ),
			'manage_options',
			'anhora',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register setting.
	 */
	public static function register_settings(): void {
		register_setting(
			'anhora_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize options.
	 *
	 * @param mixed $input Raw POST.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$current = self::get();
		$input   = is_array( $input ) ? $input : array();

		$out = array(
			'api_base'           => esc_url_raw( (string) ( $input['api_base'] ?? $current['api_base'] ) ),
			'widget_id'          => sanitize_text_field( (string) ( $input['widget_id'] ?? '' ) ),
			'installation_id'    => sanitize_text_field( (string) ( $input['installation_id'] ?? '' ) ),
			'deployment_key'     => sanitize_text_field( (string) ( $input['deployment_key'] ?? '' ) ),
			'loader_url'         => esc_url_raw( (string) ( $input['loader_url'] ?? $current['loader_url'] ) ),
			'embed_enabled'      => empty( $input['embed_enabled'] ) ? 0 : 1,
			'sync_on_save'       => empty( $input['sync_on_save'] ) ? 0 : 1,
			'knowledge_geo_tag'  => sanitize_text_field( (string) ( $input['knowledge_geo_tag'] ?? '' ) ),
			'knowledge_page_ids' => array(),
			'ingest_secret'      => (string) $current['ingest_secret'],
		);

		if ( isset( $input['ingest_secret'] ) && '' !== trim( (string) $input['ingest_secret'] ) ) {
			$out['ingest_secret'] = sanitize_text_field( (string) $input['ingest_secret'] );
		}

		if ( ! empty( $input['knowledge_page_ids'] ) && is_array( $input['knowledge_page_ids'] ) ) {
			$out['knowledge_page_ids'] = array_values(
				array_filter(
					array_map( 'absint', $input['knowledge_page_ids'] )
				)
			);
		}

		return $out;
	}

	/**
	 * Settings UI.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		$pages    = get_pages( array( 'sort_column' => 'post_title' ) );
		$notice   = isset( $_GET['anhora_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['anhora_notice'] ) ) : '';
		$woo      = class_exists( 'WooCommerce' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Anhora', 'anhora' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'anhora_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="anhora_api_base"><?php esc_html_e( 'API base', 'anhora' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_base]" id="anhora_api_base" type="url" class="regular-text" value="<?php echo esc_attr( (string) $settings['api_base'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="anhora_widget_id"><?php esc_html_e( 'Widget ID', 'anhora' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[widget_id]" id="anhora_widget_id" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['widget_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="anhora_installation_id"><?php esc_html_e( 'Integration ID', 'anhora' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[installation_id]" id="anhora_installation_id" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['installation_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Website content integration ID from Anhora Dashboard.', 'anhora' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="anhora_ingest_secret"><?php esc_html_e( 'Ingest secret', 'anhora' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ingest_secret]" id="anhora_ingest_secret" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $settings['ingest_secret'] ? esc_attr__( '(unchanged)', 'anhora' ) : ''; ?>" />
							<p class="description"><?php esc_html_e( 'From Anhora Dashboard → Site Chat. Leave blank to keep the current secret.', 'anhora' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="anhora_deployment_key"><?php esc_html_e( 'Deployment key', 'anhora' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[deployment_key]" id="anhora_deployment_key" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['deployment_key'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="anhora_loader_url"><?php esc_html_e( 'Loader URL', 'anhora' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[loader_url]" id="anhora_loader_url" type="url" class="regular-text" value="<?php echo esc_attr( (string) $settings['loader_url'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Embed widget', 'anhora' ); ?></th>
						<td><label><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[embed_enabled]" type="checkbox" value="1" <?php checked( ! empty( $settings['embed_enabled'] ) ); ?> /> <?php esc_html_e( 'Load Anhora chat on the storefront', 'anhora' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sync on save', 'anhora' ); ?></th>
						<td><label><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_on_save]" type="checkbox" value="1" <?php checked( ! empty( $settings['sync_on_save'] ) ); ?> /> <?php esc_html_e( 'Push knowledge (and Woo catalog) when content is saved', 'anhora' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="anhora_geo"><?php esc_html_e( 'Default geo tag', 'anhora' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[knowledge_geo_tag]" id="anhora_geo" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['knowledge_geo_tag'] ); ?>" placeholder="RU" />
							<p class="description"><?php esc_html_e( 'Optional country/region label prepended to synced knowledge (e.g. Country: RU). Use separate pages per market when rules differ.', 'anhora' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Knowledge pages', 'anhora' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[knowledge_page_ids][]" multiple size="12" style="min-width:320px">
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( in_array( (int) $page->ID, array_map( 'intval', $settings['knowledge_page_ids'] ), true ) ); ?>>
										<?php echo esc_html( $page->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Select shipping, payment, returns, and FAQ pages. Content is pushed as durable Anhora knowledge.', 'anhora' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'anhora' ) ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Manual sync', 'anhora' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px">
				<input type="hidden" name="action" value="anhora_sync_knowledge" />
				<?php wp_nonce_field( 'anhora_sync_knowledge' ); ?>
				<?php submit_button( __( 'Sync knowledge now', 'anhora' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( $woo ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
					<input type="hidden" name="action" value="anhora_sync_catalog" />
					<?php wp_nonce_field( 'anhora_sync_catalog' ); ?>
					<?php submit_button( __( 'Full catalog + shipping knowledge sync', 'anhora' ), 'secondary', 'submit', false ); ?>
				</form>
				<p class="description"><?php esc_html_e( 'WooCommerce detected: catalog ingest and Host Bridge are enabled.', 'anhora' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Install WooCommerce to enable catalog sync and cart/order Host Bridge.', 'anhora' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Manual knowledge sync.
	 */
	public static function handle_sync_knowledge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'anhora' ) );
		}
		check_admin_referer( 'anhora_sync_knowledge' );
		$result = Anhora_Knowledge_Sync::sync_selected_pages( true );
		$msg    = $result['ok']
			? sprintf(
				/* translators: %d: upserted count */
				__( 'Knowledge sync OK (%d items).', 'anhora' ),
				(int) ( $result['count'] ?? 0 )
			)
			: sprintf(
				/* translators: %s: error */
				__( 'Knowledge sync failed: %s', 'anhora' ),
				(string) ( $result['error'] ?? 'unknown' )
			);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'anhora',
					'anhora_notice' => rawurlencode( $msg ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Manual Woo catalog sync.
	 */
	public static function handle_sync_catalog(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'anhora' ) );
		}
		check_admin_referer( 'anhora_sync_catalog' );
		if ( ! class_exists( 'Anhora_Woo_Catalog_Sync' ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=anhora' ) );
			exit;
		}
		$result = Anhora_Woo_Catalog_Sync::sync_full();
		Anhora_Woo_Shipping_Knowledge::sync();
		$msg = $result['ok']
			? __( 'Catalog snapshot queued in the background. Shipping/payment knowledge refreshed.', 'anhora' )
			: sprintf(
				/* translators: %s: error */
				__( 'Catalog sync failed: %s', 'anhora' ),
				(string) ( $result['error'] ?? 'unknown' )
			);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'anhora',
					'anhora_notice' => rawurlencode( $msg ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
