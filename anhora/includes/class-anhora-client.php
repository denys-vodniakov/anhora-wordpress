<?php
/**
 * Anhora V2 connector client.
 *
 * @package Anhora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Typed transport for connector events and atomic snapshots.
 */
class Anhora_Client {

	/**
	 * Push an idempotent delta event batch.
	 *
	 * @param string                    $namespace Source-owned namespace.
	 * @param array<int,array<string,mixed>> $events Events.
	 * @return array{ok:bool,status:int,body?:mixed,error?:string}
	 */
	public static function events( string $namespace, array $events ): array {
		return self::request(
			'POST',
			'/events',
			array(
				'schemaVersion' => 2,
				'namespace'     => $namespace,
				'events'        => $events,
			)
		);
	}

	/**
	 * Begin a snapshot generation.
	 *
	 * @return array{ok:bool,status:int,body?:mixed,error?:string}
	 */
	public static function begin_snapshot( string $namespace, string $entity_type ): array {
		return self::request(
			'POST',
			'/snapshots',
			array(
				'schemaVersion' => 2,
				'namespace'     => $namespace,
				'entityType'    => $entity_type,
			)
		);
	}

	/**
	 * Upload one idempotent page.
	 *
	 * @param array<int,array<string,mixed>> $items Snapshot items.
	 * @return array{ok:bool,status:int,body?:mixed,error?:string}
	 */
	public static function snapshot_page( string $run_id, int $page, array $items ): array {
		return self::request(
			'PUT',
			'/snapshots/' . rawurlencode( $run_id ) . '/pages/' . $page,
			array( 'items' => $items )
		);
	}

	/**
	 * Commit a complete snapshot.
	 *
	 * @return array{ok:bool,status:int,body?:mixed,error?:string}
	 */
	public static function commit_snapshot( string $run_id, int $expected_pages ): array {
		return self::request(
			'POST',
			'/snapshots/' . rawurlencode( $run_id ) . '/commit',
			array( 'expectedPages' => $expected_pages )
		);
	}

	/**
	 * Abort an incomplete snapshot.
	 *
	 * @return array{ok:bool,status:int,body?:mixed,error?:string}
	 */
	public static function abort_snapshot( string $run_id ): array {
		return self::request( 'POST', '/snapshots/' . rawurlencode( $run_id ) . '/abort', null );
	}

	/**
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Installation-relative V2 path.
	 * @param array<string,mixed>|null $body   JSON body.
	 * @return array{ok:bool,status:int,body?:mixed,error?:string}
	 */
	private static function request( string $method, string $path, ?array $body ): array {
		$settings     = Anhora_Settings::get();
		$api_base     = untrailingslashit( (string) $settings['api_base'] );
		$secret       = (string) $settings['ingest_secret'];
		$installation = (string) $settings['installation_id'];

		if ( '' === $api_base || '' === $secret || '' === $installation ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'error'  => 'Missing api_base, installation_id, or ingest_secret',
			);
		}

		$url  = self::api_root( $api_base ) . '/v2/integrations/' . rawurlencode( $installation ) . $path;
		$timeout = '/commit' === substr( $path, -7 ) ? 130 : ( false !== strpos( $path, '/pages/' ) ? 60 : 20 );
		$args    = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Content-Type'           => 'application/json',
				'X-Anhora-Ingest-Secret' => $secret,
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'error'  => $response->get_error_message(),
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		return array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'body'   => $decoded,
			'error'  => $status >= 200 && $status < 300 ? null : $raw,
		);
	}

	/**
	 * Normalize host, /api, or /api/v1 to the unversioned API root.
	 */
	private static function api_root( string $api_base ): string {
		$base = preg_replace( '#/v[0-9]+$#', '', untrailingslashit( $api_base ) );
		if ( preg_match( '#/api$#', (string) $base ) ) {
			return (string) $base;
		}
		return (string) $base . '/api';
	}
}
