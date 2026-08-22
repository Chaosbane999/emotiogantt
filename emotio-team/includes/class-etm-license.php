<?php
/**
 * Emotio license module — client for the Emotio License Manager running on
 * emotio-design-group.co.uk (REST namespace emotio-license/v1).
 *
 * Speaks the full ELM contract:
 * - POST /activate, /check, /deactivate, /trial with product_slug,
 *   site_url, installation_id, plugin_version and license_key.
 * - Responses carry an Ed25519-signed token (base64url payload.signature);
 *   when libsodium is available the signature is verified against the
 *   server's /public-key, and the signed claims are treated as canonical.
 * - Licenses AND 14-day free trials are supported.
 * - Offline resilience: the signed grace_until claim keeps the site
 *   licensed if the license server is temporarily unreachable; the plugin
 *   itself never stops working over a licensing hiccup.
 *
 * NOTE: the product must be registered on the license server — add
 * "Emotio Team | emotio-team" under the Emotio License Manager's products
 * settings on emotio-design-group.co.uk.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_License {

	const OPTION           = 'etm_license';
	const INSTALL_OPTION   = 'etm_installation_id';
	const PUBKEY_OPTION    = 'etm_license_pubkey';
	const CRON_HOOK        = 'etm_license_check';
	const PRODUCT          = 'emotio-team';
	const GRACE_FALLBACK   = 7; // Days, mirrors the server's default grace_days.

	/** @var array|null Cached license state. */
	protected static $state = null;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_etm_license', array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'revalidate' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		// Pre-seeded key from wp-config: activate automatically once.
		if ( defined( 'ETM_LICENSE_KEY' ) && ETM_LICENSE_KEY && self::normalize_key( ETM_LICENSE_KEY ) !== self::get_state( 'key' ) ) {
			self::activate( ETM_LICENSE_KEY );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * License server REST base. Override with the ETM_LICENSE_API constant
	 * or the etm_license_api_base filter (e.g. for a staging server).
	 */
	public static function api_base() {
		$base = defined( 'ETM_LICENSE_API' ) ? ETM_LICENSE_API : 'https://emotio-design-group.co.uk/wp-json/emotio-license/v1';
		return untrailingslashit( apply_filters( 'etm_license_api_base', $base ) );
	}

	/**
	 * Product slug as registered on the license server.
	 */
	public static function product() {
		return apply_filters( 'etm_license_product', self::PRODUCT );
	}

	/**
	 * Stable per-install identifier (the server tracks trials and
	 * activations against it). Matches ELM's /^[a-zA-Z0-9._:-]+$/ rule.
	 */
	public static function installation_id() {
		$id = get_option( self::INSTALL_OPTION );
		if ( ! $id ) {
			$id = 'etm-' . strtolower( wp_generate_password( 24, false, false ) );
			update_option( self::INSTALL_OPTION, $id, false );
		}
		return $id;
	}

	protected static function normalize_key( $key ) {
		return preg_replace( '/[^A-Z0-9-]/', '', strtoupper( trim( (string) $key ) ) );
	}

	/* ------------------------------------------------------------ state */

	protected static function default_state() {
		return array(
			'key'          => '',
			'is_trial'     => 0,
			'status'       => 'inactive', // inactive | active | expired | invalid | offline
			'license_type' => '',
			'expires_at'   => '',
			'site_limit'   => 0,
			'active_sites' => 0,
			'grace_until'  => 0,
			'last_check'   => 0,
			'last_error'   => '',
		);
	}

	public static function get_state( $field = null ) {
		if ( null === self::$state ) {
			self::$state = wp_parse_args( (array) get_option( self::OPTION, array() ), self::default_state() );
		}
		if ( null === $field ) {
			return self::$state;
		}
		return isset( self::$state[ $field ] ) ? self::$state[ $field ] : null;
	}

	protected static function save_state( array $changes ) {
		self::$state = array_merge( self::get_state(), $changes );
		update_option( self::OPTION, self::$state, false );
	}

	/**
	 * Is the site licensed (paid license or running trial)?
	 * Gate premium behaviour on the `etm_is_licensed` filter.
	 */
	public static function is_licensed() {
		$state    = self::get_state();
		$licensed = 'active' === $state['status'];

		// Server unreachable: the signed grace_until claim from the last
		// good response keeps the site licensed through short outages.
		if ( 'offline' === $state['status'] ) {
			$grace_until = (int) $state['grace_until'];
			if ( ! $grace_until ) {
				$grace_until = (int) $state['last_check'] + self::GRACE_FALLBACK * DAY_IN_SECONDS;
			}
			$licensed = time() < $grace_until;
		}

		return (bool) apply_filters( 'etm_is_licensed', $licensed, $state );
	}

	/* -------------------------------------------------------- API calls */

	/**
	 * POST to an ELM endpoint.
	 *
	 * @param string $endpoint activate|check|deactivate|trial
	 * @param array  $extra    Extra body fields (license_key, customer_email).
	 * @return array|WP_Error Decoded response body (success or ELM error body).
	 */
	protected static function request( $endpoint, array $extra = array() ) {
		$response = wp_remote_post(
			self::api_base() . '/' . $endpoint,
			array(
				'timeout' => 15,
				'body'    => array_merge(
					array(
						'product_slug'    => self::product(),
						'site_url'        => home_url( '/' ),
						'installation_id' => self::installation_id(),
						'plugin_version'  => ETM_VERSION,
					),
					$extra
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'etm_license_http', sprintf( /* translators: %d: HTTP status */ __( 'Unexpected response from the license server (HTTP %d).', 'emotio-team' ), $code ) );
		}

		// ELM errors come back as WP_Error REST bodies: { code, message, data }.
		if ( $code >= 400 || ( isset( $body['code'] ) && isset( $body['message'] ) && ! isset( $body['status'] ) ) ) {
			// Rate limiting / server hiccups are transient, not verdicts.
			if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true ) ) {
				return new WP_Error( 'etm_license_unavailable', isset( $body['message'] ) ? $body['message'] : __( 'The license server is temporarily unavailable.', 'emotio-team' ) );
			}
			return array(
				'success' => false,
				'status'  => 'invalid',
				'message' => isset( $body['message'] ) ? $body['message'] : __( 'The license request was rejected.', 'emotio-team' ),
				'code'    => isset( $body['code'] ) ? $body['code'] : '',
			);
		}

		return $body;
	}

	/**
	 * Verify the Ed25519-signed token and return its claims.
	 * Falls back to the plain response body when libsodium or the public
	 * key is unavailable (transport is HTTPS either way).
	 *
	 * @return array|null Claims array, or null if the signature is bad.
	 */
	protected static function token_claims( array $body ) {
		if ( empty( $body['token'] ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return $body;
		}

		$parts = explode( '.', $body['token'] );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$payload   = base64_decode( strtr( $parts[0], '-_', '+/' ) );
		$signature = base64_decode( strtr( $parts[1], '-_', '+/' ) );
		if ( ! $payload || ! $signature ) {
			return null;
		}

		$pubkey = self::public_key();
		if ( ! $pubkey ) {
			return $body; // Can't verify — trust the HTTPS response.
		}

		try {
			if ( ! sodium_crypto_sign_verify_detached( $signature, $payload, $pubkey ) ) {
				return null;
			}
		} catch ( Exception $e ) {
			return $body;
		}

		$claims = json_decode( $payload, true );
		if ( ! is_array( $claims ) || self::product() !== ( $claims['product'] ?? '' ) ) {
			return null;
		}
		return $claims;
	}

	/**
	 * Fetch and cache the server's Ed25519 public key.
	 */
	protected static function public_key() {
		$cached = get_option( self::PUBKEY_OPTION );
		if ( $cached ) {
			return base64_decode( $cached );
		}
		$response = wp_remote_get( self::api_base() . '/public-key', array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['public_key'] ) ) {
			return '';
		}
		$raw = base64_decode( $body['public_key'] );
		if ( ! $raw || 32 !== strlen( $raw ) ) {
			return '';
		}
		update_option( self::PUBKEY_OPTION, $body['public_key'], false );
		return $raw;
	}

	/**
	 * Store the outcome of a successful (2xx) ELM response.
	 *
	 * @return true|WP_Error
	 */
	protected static function apply_response( array $body, array $overrides = array() ) {
		$claims = self::token_claims( $body );
		if ( null === $claims ) {
			return new WP_Error( 'etm_license_signature', __( 'The license response failed signature verification.', 'emotio-team' ) );
		}

		$status = sanitize_key( $claims['status'] ?? ( $body['status'] ?? 'invalid' ) );
		if ( ! in_array( $status, array( 'active', 'expired' ), true ) ) {
			$status = 'invalid';
		}

		self::save_state(
			array_merge(
				array(
					'status'       => $status,
					'license_type' => sanitize_text_field( $claims['license_type'] ?? '' ),
					'expires_at'   => sanitize_text_field( $claims['expires_at'] ?? '' ),
					'site_limit'   => (int) ( $claims['site_limit'] ?? 0 ),
					'active_sites' => (int) ( $claims['active_sites'] ?? 0 ),
					'grace_until'  => (int) ( $claims['grace_until'] ?? 0 ),
					'last_check'   => time(),
					'last_error'   => 'active' === $status ? '' : sanitize_text_field( $body['message'] ?? '' ),
				),
				$overrides
			)
		);

		if ( 'active' !== $status ) {
			$message = self::get_state( 'last_error' );
			if ( ! $message ) {
				$message = 'expired' === $status
					? __( 'This license has expired — renew to keep receiving updates and support.', 'emotio-team' )
					: __( 'The license key was not accepted.', 'emotio-team' );
			}
			return new WP_Error( 'etm_license_' . $status, $message );
		}
		return true;
	}

	/**
	 * Mark the server unreachable, preserving the last known good claims.
	 */
	protected static function mark_offline( WP_Error $error ) {
		$changes = array( 'last_error' => $error->get_error_message() );
		if ( in_array( self::get_state( 'status' ), array( 'active', 'offline' ), true ) ) {
			$changes['status'] = 'offline';
		}
		self::save_state( $changes );
	}

	/**
	 * Activate a license key for this site.
	 *
	 * @return true|WP_Error
	 */
	public static function activate( $key ) {
		$key = self::normalize_key( $key );
		if ( strlen( $key ) < 20 ) {
			return new WP_Error( 'etm_license_empty', __( 'Please enter your full license key (EMOTIO-XXXXX-XXXXX-XXXXX-XXXXX).', 'emotio-team' ) );
		}

		self::save_state( array( 'key' => $key, 'is_trial' => 0 ) );
		$body = self::request( 'activate', array( 'license_key' => $key ) );

		if ( is_wp_error( $body ) ) {
			self::mark_offline( $body );
			return $body;
		}
		if ( empty( $body['token'] ) && empty( $body['success'] ) ) {
			self::save_state(
				array(
					'status'     => 'invalid',
					'last_check' => time(),
					'last_error' => sanitize_text_field( $body['message'] ?? '' ),
				)
			);
			return new WP_Error( 'etm_license_invalid', self::get_state( 'last_error' ) );
		}
		return self::apply_response( $body, array( 'is_trial' => 0 ) );
	}

	/**
	 * Start the free trial for this site.
	 *
	 * @return true|WP_Error
	 */
	public static function start_trial() {
		$body = self::request( 'trial', array( 'customer_email' => sanitize_email( get_option( 'admin_email' ) ) ) );

		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['token'] ) && empty( $body['success'] ) ) {
			return new WP_Error( 'etm_trial_failed', sanitize_text_field( $body['message'] ?? __( 'The trial could not be started.', 'emotio-team' ) ) );
		}
		return self::apply_response( $body, array( 'is_trial' => 1, 'key' => '' ) );
	}

	/**
	 * Release this site's activation.
	 */
	public static function deactivate() {
		$key = self::get_state( 'key' );
		if ( $key ) {
			// Best effort — never trap a site behind a dead API.
			self::request( 'deactivate', array( 'license_key' => $key ) );
		}
		self::save_state( self::default_state() );
		return true;
	}

	/**
	 * Daily re-validation (cron + manual re-check).
	 */
	public static function revalidate() {
		$state = self::get_state();

		if ( $state['is_trial'] ) {
			$body = self::request( 'trial' );
		} elseif ( $state['key'] ) {
			$body = self::request( 'check', array( 'license_key' => $state['key'] ) );
		} else {
			return;
		}

		if ( is_wp_error( $body ) ) {
			self::mark_offline( $body );
			return;
		}
		if ( empty( $body['token'] ) && empty( $body['success'] ) ) {
			self::save_state(
				array(
					'status'     => 'invalid',
					'last_check' => time(),
					'last_error' => sanitize_text_field( $body['message'] ?? '' ),
				)
			);
			return;
		}
		self::apply_response( $body );
	}

	/* --------------------------------------------------------- admin UI */

	public static function add_page() {
		add_submenu_page(
			'edit.php?post_type=team_member',
			__( 'Emotio License', 'emotio-team' ),
			__( 'License', 'emotio-team' ),
			'manage_options',
			'etm-license',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the license.', 'emotio-team' ) );
		}
		check_admin_referer( 'etm_license' );

		$do     = isset( $_POST['etm_do'] ) ? sanitize_key( $_POST['etm_do'] ) : '';
		$notice = '';

		if ( 'activate' === $do ) {
			$key    = isset( $_POST['etm_key'] ) ? sanitize_text_field( wp_unslash( $_POST['etm_key'] ) ) : '';
			$result = self::activate( $key );
			$notice = is_wp_error( $result ) ? 'error:' . $result->get_error_message() : 'activated';
		} elseif ( 'trial' === $do ) {
			$result = self::start_trial();
			$notice = is_wp_error( $result ) ? 'error:' . $result->get_error_message() : 'trial';
		} elseif ( 'deactivate' === $do ) {
			self::deactivate();
			$notice = 'deactivated';
		} elseif ( 'recheck' === $do ) {
			self::revalidate();
			$notice = 'rechecked';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'  => ETM_CPT::POST_TYPE,
					'page'       => 'etm-license',
					'etm_notice' => rawurlencode( $notice ),
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	protected static function masked_key() {
		$key = self::get_state( 'key' );
		if ( strlen( $key ) <= 12 ) {
			return $key ? str_repeat( '•', strlen( $key ) ) : '';
		}
		return substr( $key, 0, 7 ) . str_repeat( '•', 12 ) . substr( $key, -5 );
	}

	protected static function expires_label() {
		$expires = self::get_state( 'expires_at' );
		if ( ! $expires ) {
			return __( 'Never (perpetual)', 'emotio-team' );
		}
		$timestamp = strtotime( $expires . ' UTC' );
		return $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : $expires;
	}

	protected static function status_label() {
		$state = self::get_state();
		switch ( $state['status'] ) {
			case 'active':
				if ( $state['is_trial'] ) {
					$timestamp = strtotime( $state['expires_at'] . ' UTC' );
					$days      = $timestamp ? max( 0, (int) ceil( ( $timestamp - time() ) / DAY_IN_SECONDS ) ) : 0;
					/* translators: %d: days left in trial */
					return array( sprintf( _n( 'Trial — %d day remaining', 'Trial — %d days remaining', $days, 'emotio-team' ), $days ), '#2271b1' );
				}
				return array( __( 'Active', 'emotio-team' ), '#00a32a' );
			case 'offline':
				return self::is_licensed()
					? array( __( 'Active (license server unreachable — grace period)', 'emotio-team' ), '#dba617' )
					: array( __( 'Grace period expired — please re-check', 'emotio-team' ), '#d63638' );
			case 'expired':
				return array( $state['is_trial'] ? __( 'Trial expired', 'emotio-team' ) : __( 'Expired', 'emotio-team' ), '#d63638' );
			case 'invalid':
				return array( __( 'Invalid', 'emotio-team' ), '#d63638' );
			default:
				return array( __( 'Not activated', 'emotio-team' ), '#646970' );
		}
	}

	public static function render_page() {
		$state  = self::get_state();
		$locked = defined( 'ETM_LICENSE_KEY' ) && ETM_LICENSE_KEY;
		list( $label, $color ) = self::status_label();
		$has_license = $state['key'] || $state['is_trial'];

		$notice = isset( $_GET['etm_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['etm_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Emotio License', 'emotio-team' ); ?></h1>

			<?php if ( 'activated' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License activated — thank you!', 'emotio-team' ); ?></p></div>
			<?php elseif ( 'trial' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Your free trial has started. Enjoy!', 'emotio-team' ); ?></p></div>
			<?php elseif ( 'deactivated' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'License deactivated for this site.', 'emotio-team' ); ?></p></div>
			<?php elseif ( 'rechecked' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'License status refreshed.', 'emotio-team' ); ?></p></div>
			<?php elseif ( 0 === strpos( $notice, 'error:' ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( substr( $notice, 6 ) ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:680px;">
				<h2 style="margin-top:0;">
					<?php esc_html_e( 'Status:', 'emotio-team' ); ?>
					<span style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $label ); ?></span>
				</h2>
				<table class="form-table" role="presentation">
					<?php if ( $state['key'] ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'License key', 'emotio-team' ); ?></th><td><code><?php echo esc_html( self::masked_key() ); ?></code></td></tr>
					<?php endif; ?>
					<?php if ( $has_license && 'inactive' !== $state['status'] ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Renews / expires', 'emotio-team' ); ?></th><td><?php echo esc_html( self::expires_label() ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $state['site_limit'] && ! $state['is_trial'] ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Websites', 'emotio-team' ); ?></th><td><?php echo esc_html( $state['active_sites'] . ' / ' . $state['site_limit'] ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $state['last_check'] ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Last checked', 'emotio-team' ); ?></th>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['last_check'] ) ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $state['last_error'] ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Last message', 'emotio-team' ); ?></th><td><code><?php echo esc_html( $state['last_error'] ); ?></code></td></tr>
					<?php endif; ?>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'etm_license' ); ?>
					<input type="hidden" name="action" value="etm_license">

					<p>
						<label for="etm-key"><strong><?php esc_html_e( 'License key', 'emotio-team' ); ?></strong></label><br>
						<input type="text" id="etm-key" name="etm_key" class="regular-text code"
							value="<?php echo esc_attr( $locked ? self::masked_key() : '' ); ?>"
							<?php echo $locked ? 'readonly' : ''; ?>
							placeholder="EMOTIO-XXXXX-XXXXX-XXXXX-XXXXX" autocomplete="off" spellcheck="false">
					</p>
					<?php if ( $locked ) : ?>
						<p class="description"><?php esc_html_e( 'This key is set in wp-config.php via ETM_LICENSE_KEY and cannot be edited here.', 'emotio-team' ); ?></p>
					<?php endif; ?>

					<p>
						<?php if ( ! $has_license ) : ?>
							<button type="submit" name="etm_do" value="activate" class="button button-primary"><?php esc_html_e( 'Activate', 'emotio-team' ); ?></button>
							<button type="submit" name="etm_do" value="trial" class="button"><?php esc_html_e( 'Start 14-day free trial', 'emotio-team' ); ?></button>
						<?php else : ?>
							<?php if ( ! $locked ) : ?>
								<button type="submit" name="etm_do" value="activate" class="button button-primary"><?php echo $state['is_trial'] ? esc_html__( 'Activate license key', 'emotio-team' ) : esc_html__( 'Update key', 'emotio-team' ); ?></button>
							<?php endif; ?>
							<button type="submit" name="etm_do" value="recheck" class="button"><?php esc_html_e( 'Re-check status', 'emotio-team' ); ?></button>
							<?php if ( $state['key'] && ! $locked ) : ?>
								<button type="submit" name="etm_do" value="deactivate" class="button"><?php esc_html_e( 'Deactivate this site', 'emotio-team' ); ?></button>
							<?php endif; ?>
						<?php endif; ?>
					</p>
				</form>

				<p class="description">
					<?php esc_html_e( 'Licenses are managed by Emotio Design Group. Your key is in your Emotio welcome email; the plugin keeps working on your site through any licensing hiccup.', 'emotio-team' ); ?>
					<a href="https://emotio-design-group.co.uk" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Contact Emotio →', 'emotio-team' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Gentle admin nudges — only on plugin screens, never site-wide nagging.
	 */
	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'team_member' ) || 'team_member_page_etm-license' === $screen->id ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || self::is_licensed() ) {
			return;
		}

		$state = self::get_state();
		$url   = admin_url( 'edit.php?post_type=team_member&page=etm-license' );

		if ( 'expired' === $state['status'] ) {
			$message = $state['is_trial']
				? __( 'Your Emotio Team trial has ended — activate a license to keep updates and support.', 'emotio-team' )
				: __( 'Your Emotio Team license has expired — renew to keep receiving updates and support.', 'emotio-team' );
		} elseif ( in_array( $state['status'], array( 'invalid', 'offline' ), true ) ) {
			$message = __( 'Your Emotio Team license needs attention.', 'emotio-team' );
		} else {
			$message = __( 'Activate your Emotio Team license (or start the free trial) to unlock updates and support.', 'emotio-team' );
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( $message ),
			esc_url( $url ),
			esc_html__( 'Open license settings', 'emotio-team' )
		);
	}
}
