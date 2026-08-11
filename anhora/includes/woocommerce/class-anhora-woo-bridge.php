<?php
/**
 * WooCommerce Host Bridge: PDP catalog, user, orders, add-to-cart base.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Session-only commerce context for the widget.
 */
class Anhora_Woo_Bridge {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'anhora_host_bridge_boot', array( __CLASS__, 'enrich_boot' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'add_cart_base' ), 30 );
	}

	/**
	 * Inline add-to-cart base URL for host-bridge.js fallback.
	 */
	public static function add_cart_base(): void {
		if ( ! wp_script_is( 'anhora-host-bridge', 'enqueued' ) ) {
			return;
		}
		wp_add_inline_script(
			'anhora-host-bridge',
			'window.__ANHORA_ADD_TO_CART_BASE__ = ' . wp_json_encode( esc_url_raw( home_url( '/' ) ) ) . ';',
			'before'
		);
	}

	/**
	 * Attach products / user / orders to boot payload.
	 *
	 * @param array<string,mixed> $bridge Existing bridge.
	 * @return array<string,mixed>
	 */
	public static function enrich_boot( array $bridge ): array {
		$products = self::featured_products();
		if ( $products ) {
			$bridge['products'] = $products;
		}

		$user = self::current_user();
		if ( $user ) {
			$bridge['user'] = $user;
		}

		$orders = self::order_history();
		if ( $orders ) {
			$bridge['orders'] = $orders;
		}

		return $bridge;
	}

	/**
	 * Visible products on PDP / shop loops (Anhora Product shape).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function featured_products(): array {
		$out = array();

		if ( is_product() ) {
			$product = wc_get_product( get_the_ID() );
			$item    = Anhora_Woo_Mapper::to_ingest_item( $product );
			if ( $item ) {
				$out[] = $item['product'];
			}
			return $out;
		}

		if ( is_shop() || is_product_taxonomy() ) {
			global $wp_query;
			if ( $wp_query && ! empty( $wp_query->posts ) ) {
				$count = 0;
				foreach ( $wp_query->posts as $post ) {
					if ( $count >= 12 ) {
						break;
					}
					$product = wc_get_product( $post->ID );
					$item    = Anhora_Woo_Mapper::to_ingest_item( $product );
					if ( $item ) {
						$out[] = $item['product'];
						++$count;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Logged-in customer info with country hints.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function current_user(): ?array {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$user_id = get_current_user_id();
		$customer = new WC_Customer( $user_id );
		$shipping = $customer->get_shipping_country();
		$billing  = $customer->get_billing_country();

		return array(
			'id'              => (string) $user_id,
			'email'           => $customer->get_email(),
			'name'            => trim( $customer->get_first_name() . ' ' . $customer->get_last_name() ),
			'country'         => $shipping ? $shipping : $billing,
			'shippingCountry' => $shipping,
			'billingCountry'  => $billing,
		);
	}

	/**
	 * Recent orders for the logged-in customer (session Bridge only).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function order_history(): array {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => get_current_user_id(),
				'limit'       => 10,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		$out = array();
		foreach ( $orders as $order ) {
			/** @var WC_Order $order */
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = array(
					'id'       => (string) $item->get_product_id(),
					'name'     => $item->get_name(),
					'quantity' => (int) $item->get_quantity(),
					'sku'      => ( $product = $item->get_product() ) ? (string) $product->get_sku() : '',
				);
			}

			$out[] = array(
				'id'            => (string) $order->get_id(),
				'number'        => (string) $order->get_order_number(),
				'status'        => (string) $order->get_status(),
				'paymentStatus' => $order->is_paid() ? 'paid' : (string) $order->get_status(),
				'currency'      => $order->get_currency(),
				'total'         => (float) $order->get_total(),
				'createdAt'     => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
				'items'         => $items,
			);
		}

		return $out;
	}
}
