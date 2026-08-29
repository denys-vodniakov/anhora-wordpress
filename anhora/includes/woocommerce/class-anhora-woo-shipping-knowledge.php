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

	public const PREFIX    = 'woocommerce:';
	public const NAMESPACE = 'woocommerce.operations';

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
	 * Reconcile shipping and payment knowledge in one atomic namespace snapshot.
	 *
	 * @return array{ok:bool,count?:int,error?:string}
	 */
	public static function sync(): array {
		$knowledge = array_merge( self::shipping_items(), self::payment_items() );
		$items     = array_map(
			static function ( array $item ): array {
				$payload = array(
					'title'   => (string) $item['name'],
					'content' => (string) $item['content'],
				);
				if ( isset( $item['storeOperation'] ) && is_array( $item['storeOperation'] ) ) {
					$payload['storeOperation'] = $item['storeOperation'];
				}
				return array(
					'externalId' => (string) $item['externalId'],
					'payload'    => $payload,
				);
			},
			$knowledge
		);

		$begin = Anhora_Client::begin_snapshot( self::NAMESPACE, 'knowledge_document' );
		if ( ! $begin['ok'] || empty( $begin['body']['runId'] ) ) {
			return array(
				'ok'    => false,
				'error' => (string) ( $begin['error'] ?? 'snapshot begin failed' ),
			);
		}
		$run_id = (string) $begin['body']['runId'];
		$pages  = $items ? 1 : 0;
		if ( $items ) {
			$page = Anhora_Client::snapshot_page( $run_id, 0, $items );
			if ( ! $page['ok'] ) {
				Anhora_Client::abort_snapshot( $run_id );
				return array( 'ok' => false, 'error' => (string) ( $page['error'] ?? 'snapshot page failed' ) );
			}
		}
		$result = Anhora_Client::commit_snapshot( $run_id, $pages );
		if ( ! $result['ok'] ) {
			return array( 'ok' => false, 'error' => (string) ( $result['error'] ?? 'snapshot commit failed' ) );
		}

		return array(
			'ok'    => true,
			'count' => count( $knowledge ),
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
		$location_items  = array();
		foreach ( $locations as $location ) {
			$code = isset( $location->code ) ? (string) $location->code : '';
			$type = isset( $location->type ) ? (string) $location->type : '';
			if ( '' !== $code ) {
				$location_labels[] = strtoupper( $type ) . ':' . $code;
				$location_items[]  = array(
					'type' => $type ? strtolower( $type ) : 'other',
					'code' => $code,
				);
			}
		}
		$geo = $location_labels ? implode( ', ', $location_labels ) : 'OTHER';

		$method_lines = array();
		$method_items = array();
		foreach ( $methods as $method ) {
			if ( ! $method->is_enabled() ) {
				continue;
			}
			$title = $method->get_title();
			$method_lines[] = '- ' . $title . ' (' . $method->id . ')';
			$method_items[] = array(
				'id'    => (string) $method->id,
				'title' => (string) $title,
			);
		}

		$body  = 'Zone: ' . $name . "\n";
		$body .= 'Locations: ' . $geo . "\n";
		$body .= "Shipping methods (rules/titles only — not live rates):\n";
		$body .= $method_lines ? implode( "\n", $method_lines ) : '- (none enabled)';
		$body .= "\nAsk the customer for their country if unsure which zone applies. Do not invent delivery prices.";

		return array(
			'externalId'    => self::PREFIX . 'shipping:zone:' . $zone_id,
			'name'          => 'Shipping — ' . $name,
			'content'       => $body,
			'storeOperation' => array(
				'schemaVersion' => 1,
				'kind'          => 'shipping_zone',
				'zone'          => array(
					'id'        => (string) $zone_id,
					'title'     => (string) $name,
					'locations' => $location_items ? $location_items : array(
						array(
							'type' => 'other',
							'code' => 'OTHER',
						),
					),
				),
				'methods'       => $method_items,
				'liveRates'     => false,
			),
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
		$methods  = array();
		foreach ( $gateways as $gateway ) {
			if ( 'yes' !== $gateway->enabled ) {
				continue;
			}
			$title = $gateway->get_title();
			$desc  = wp_strip_all_tags( (string) $gateway->get_description() );
			$lines[] = '- ' . $title . ( $desc ? ': ' . $desc : '' );
			$method = array(
				'id'    => (string) $gateway->id,
				'title' => (string) $title,
			);
			if ( $desc ) {
				$method['description'] = substr( $desc, 0, 2000 );
			}
			$methods[] = $method;
		}

		$body  = "Payment methods available at checkout (titles/steps only — Anhora never processes cards):\n";
		$body .= $lines ? implode( "\n", $lines ) : '- (none enabled)';
		$body .= "\nGuide the customer through the store checkout. Do not collect card numbers in chat.";

		return array(
			array(
				'externalId'    => self::PREFIX . 'payment:methods',
				'name'          => 'Payment methods',
				'content'       => $body,
				'storeOperation' => array(
					'schemaVersion' => 1,
					'kind'          => 'payment_methods',
					'methods'       => $methods,
				),
			),
		);
	}
}
