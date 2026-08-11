<?php
/**
 * Anhora HTTP ingest client.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around POST /connectors/ingest.
 */
class Anhora_Client {

	/**
	 * POST connectors/ingest body.
	 *
	 * @param array<string,mixed> $body Ingest payload (must include widgetId).
	 * @return array{ok:bool,status:int,body?:mixed,error?:string}
	 */
	public static function ingest( array $body ): array {
		$settings = Anhora_Settings::get();
		$api_base = untrailingslashit( (string) $settings['api_base'] );
		$secret   = (string) $settings['ingest_secret'];
		$widget   = (string) $settings['widget_id'];

		if ( '' === $api_base || '' === $secret || '' === $widget ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'error'  => 'Missing api_base, widget_id, or ingest_secret',
			);
		}

		if ( empty( $body['widgetId'] ) ) {
			$body['widgetId'] = $widget;
		}

		$url = self::normalize_ingest_url( $api_base );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'          => 'application/json',
					'X-Anhora-Ingest-Secret' => $secret,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'error'  => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		return array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'body'   => $decoded,
			'error'  => $status >= 200 && $status < 300 ? null : $raw,
		);
	}

	/**
	 * Accept …/api or …/api/v1 bases.
	 */
	private static function normalize_ingest_url( string $api_base ): string {
		$api_base = untrailingslashit( $api_base );
		if ( preg_match( '#/api/v1$#', $api_base ) ) {
			return $api_base . '/connectors/ingest';
		}
		if ( preg_match( '#/api$#', $api_base ) ) {
			return $api_base . '/v1/connectors/ingest';
		}
		return $api_base . '/api/v1/connectors/ingest';
	}
}
