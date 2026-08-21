<?php
/**
 * REST API: POST /wp-json/emotio/v1/license
 *
 * Actions: activate | deactivate | check
 * Request body: action, key, site, product, version
 * Response: { success, status: valid|expired|invalid|deactivated,
 *             expires, customer, message }
 *
 * This is exactly the contract the Emotio license client
 * (Emotio Team's ETM_License and the drop-in Emotio_License_Client used
 * by AI Schema Pro) speaks.
 *
 * @package Emotio_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ELS_API {

	const RATE_LIMIT     = 30;  // Requests…
	const RATE_WINDOW    = 300; // …per seconds, per IP.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'emotio/v1',
			'/license',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'action'  => array( 'type' => 'string', 'required' => true ),
					'key'     => array( 'type' => 'string', 'required' => true ),
					'site'    => array( 'type' => 'string', 'required' => true ),
					'product' => array( 'type' => 'string', 'required' => true ),
					'version' => array( 'type' => 'string' ),
				),
			)
		);
	}

	public static function handle( WP_REST_Request $request ) {
		if ( self::rate_limited() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'status'  => 'invalid',
					'message' => __( 'Too many requests — please try again shortly.', 'emotio-license-server' ),
				),
				429
			);
		}

		$action  = sanitize_key( $request['action'] );
		$key     = strtoupper( sanitize_text_field( $request['key'] ) );
		$site    = els_normalize_site( $request['site'] );
		$product = sanitize_title( $request['product'] );

		if ( ! in_array( $action, array( 'activate', 'deactivate', 'check' ), true ) || ! $key || ! $site ) {
			return self::respond( false, 'invalid', array( 'message' => __( 'Malformed request.', 'emotio-license-server' ) ) );
		}

		if ( ! ELS_Products::exists( $product ) ) {
			return self::respond( false, 'invalid', array( 'message' => __( 'Unknown product.', 'emotio-license-server' ) ) );
		}

		$license = ELS_CPT::find_by_key( $key, $product );
		if ( ! $license ) {
			return self::respond( false, 'invalid', array( 'message' => __( 'Unknown license key for this product.', 'emotio-license-server' ) ) );
		}

		$customer = get_the_title( $license );
		$expires  = ELS_CPT::meta( $license->ID, 'expires' );
		$common   = array(
			'customer' => $customer,
			'expires'  => $expires,
		);

		if ( 'disabled' === ELS_CPT::meta( $license->ID, 'status' ) ) {
			return self::respond( false, 'invalid', $common + array( 'message' => __( 'This license has been disabled — please contact Emotio support.', 'emotio-license-server' ) ) );
		}

		if ( 'deactivate' === $action ) {
			$activations = array_filter(
				ELS_CPT::activations( $license->ID ),
				function ( $activation ) use ( $site ) {
					return $activation['site'] !== $site;
				}
			);
			ELS_CPT::save_activations( $license->ID, $activations );
			return self::respond( true, 'deactivated', $common );
		}

		if ( $expires && $expires < gmdate( 'Y-m-d' ) ) {
			return self::respond( false, 'expired', $common + array( 'message' => __( 'This license expired — renew to keep receiving updates and support.', 'emotio-license-server' ) ) );
		}

		// activate + check both end up ensuring this site holds a seat
		// (check self-heals a missing activation when a seat is free).
		$activations = ELS_CPT::activations( $license->ID );
		$found       = false;
		foreach ( $activations as &$activation ) {
			if ( $activation['site'] === $site ) {
				$activation['last_check'] = time();
				$found                    = true;
			}
		}
		unset( $activation );

		if ( ! $found ) {
			$seats = (int) ELS_CPT::meta( $license->ID, 'seats' );
			if ( $seats && count( $activations ) >= $seats ) {
				return self::respond(
					false,
					'invalid',
					$common + array(
						'message' => sprintf(
							/* translators: %d: seat count */
							__( 'All %d activations for this license are in use — deactivate another site first or upgrade your license.', 'emotio-license-server' ),
							$seats
						),
					)
				);
			}
			$activations[] = array(
				'site'       => $site,
				'time'       => time(),
				'last_check' => time(),
			);
		}
		ELS_CPT::save_activations( $license->ID, $activations );

		return self::respond( true, 'valid', $common );
	}

	protected static function respond( $success, $status, array $extra = array() ) {
		return new WP_REST_Response(
			array_merge(
				array(
					'success'  => (bool) $success,
					'status'   => $status,
					'expires'  => '',
					'customer' => '',
					'message'  => '',
				),
				$extra
			),
			200
		);
	}

	/**
	 * Cheap per-IP rate limiting via transients.
	 */
	protected static function rate_limited() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$bucket = 'els_rl_' . md5( $ip );
		$count  = (int) get_transient( $bucket );
		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}
		set_transient( $bucket, $count + 1, self::RATE_WINDOW );
		return false;
	}
}
