<?php
/**
 * Snapshot Woo shipping zones + payment titles into knowledge.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Structured geo-aware knowledge rows (rules/steps, not live rates).
 */
class Anhora_Woo_Shipping_Knowledge {

	public const PREFIX = 'woocommerce:';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_update_options', array( __CLASS__, 'maybe_sync_on_settings' ) );
	}

	/**
	 * Debounced sync when Woo settings change.
	 */
	public static function maybe_sync_on_settings(): void {
		$settings = Anhora_Settings::get();
		if ( empty( $settings['sync_on_save'] ) ) {
			return;
		}
		self::sync();
	}

	/**
	 * Push shipping + payment knowledge with replace by prefix.
	 *
	 * @return array{ok:bool,count?:int,error?:string}
	 */
	public static function sync(): array {
		$settings = Anhora_Settings::get();
		$items    = array_merge( self::shipping_items(), self::payment_items() );

		$result = Anhora_Client::ingest(
			array(
				'widgetId'  => (string) $settings['widget_id'],
				'knowledge' => $items,
				'replace'   => array(
					'knowledge'                 => true,
					'knowledgeExternalIdPrefix' => self::PREFIX,
				),
			)
		);

		if ( ! $result['ok'] ) {
			return array(
				'ok'    => false,
				'error' => (string) ( $result['error'] ?? 'ingest failed' ),
			);
		}

		return array(
			'ok'    => true,
			'count' => count( $items ),
		);
	}

	/**
	 * One knowledge row per shipping zone.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function shipping_items(): array {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}

		$items = array();

		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone    = new WC_Shipping_Zone( $zone_data['id'] );
			$items[] = self::zone_to_knowledge( $zone );
		}

		// Rest of the world (zone id 0).
		$items[] = self::zone_to_knowledge( new WC_Shipping_Zone( 0 ) );

		return $items;
	}

	/**
	 * @param WC_Shipping_Zone $zone Zone.
	 * @return array<string,mixed>
	 */
	private static function zone_to_knowledge( $zone ): array {
		$zone_id   = (int) $zone->get_id();
		$name      = $zone->get_zone_name();
		$locations = $zone->get_zone_locations();
		$methods   = $zone->get_shipping_methods( true );

		$location_labels = array();
		foreach ( $locations as $location ) {
			$code = isset( $location->code ) ? (string) $location->code : '';
			$type = isset( $location->type ) ? (string) $location->type : '';
			if ( '' !== $code ) {
				$location_labels[] = strtoupper( $type ) . ':' . $code;
			}
		}
		$geo = $location_labels ? implode( ', ', $location_labels ) : 'OTHER';

		$method_lines = array();
		foreach ( $methods as $method ) {
			if ( ! $method->is_enabled() ) {
				continue;
			}
			$title = $method->get_title();
			$method_lines[] = '- ' . $title . ' (' . $method->id . ')';
		}

		$body  = 'Zone: ' . $name . "\n";
		$body .= 'Locations: ' . $geo . "\n";
		$body .= "Shipping methods (rules/titles only — not live rates):\n";
		$body .= $method_lines ? implode( "\n", $method_lines ) : '- (none enabled)';
		$body .= "\nAsk the customer for their country if unsure which zone applies. Do not invent delivery prices.";

		return array(
			'externalId' => self::PREFIX . 'shipping:zone:' . $zone_id,
			'name'       => 'Shipping — ' . $name,
			'content'    => $body,
		);
	}

	/**
	 * Payment gateway titles as checkout steps knowledge.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function payment_items(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$lines    = array();
		foreach ( $gateways as $gateway ) {
			if ( 'yes' !== $gateway->enabled ) {
				continue;
			}
			$title = $gateway->get_title();
			$desc  = wp_strip_all_tags( (string) $gateway->get_description() );
			$lines[] = '- ' . $title . ( $desc ? ': ' . $desc : '' );
		}

		$body  = "Payment methods available at checkout (titles/steps only — Anhora never processes cards):\n";
		$body .= $lines ? implode( "\n", $lines ) : '- (none enabled)';
		$body .= "\nGuide the customer through the store checkout. Do not collect card numbers in chat.";

		return array(
			array(
				'externalId' => self::PREFIX . 'payment:methods',
				'name'       => 'Payment methods',
				'content'    => $body,
			),
		);
	}
}
