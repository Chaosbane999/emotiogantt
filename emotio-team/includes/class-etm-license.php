<?php
/**
 * Emotio license module.
 *
 * Handles license key activation/deactivation against the Emotio licensing
 * API, daily re-validation via WP-Cron, admin status UI and notices.
 *
 * Design principles:
 * - Never brick a client site: the plugin stays functional when a license
 *   is missing or the API is unreachable; an expired/invalid license only
 *   surfaces notices and flips the `etm_is_licensed` filter to false.
 * - Soft-fail with a grace period: if the licensing server can't be
 *   reached, the last known good status is trusted for 14 days.
 * - Agency friendly: define ETM_LICENSE_KEY in wp-config.php to pre-seed
 *   the key across deployments; the field then locks in the UI.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_License {

	const OPTION     = 'etm_license';
	const CRON_HOOK  = 'etm_license_check';
	const GRACE_DAYS = 14;

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
		if ( defined( 'ETM_LICENSE_KEY' ) && ETM_LICENSE_KEY && ETM_LICENSE_KEY !== self::get_state( 'key' ) ) {
			self::activate( ETM_LICENSE_KEY );
		}
	}

	/**
	 * Remove the cron event (called from the deactivation hook).
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Licensing API endpoint. Filterable so staging/dev servers can point
	 * elsewhere, or a constant can hard-pin it.
	 */
	public static function api_url() {
		$url = defined( 'ETM_LICENSE_API' ) ? ETM_LICENSE_API : 'https://licensing.emotio.co.uk/wp-json/emotio/v1/license';
		return apply_filters( 'etm_license_api_url', $url );
	}

	/* ------------------------------------------------------------ state */

	protected static function default_state() {
		return array(
			'key'        => '',
			'status'     => 'inactive', // inactive | valid | invalid | expired
			'expires'    => '',
			'customer'   => '',
			'last_check' => 0,
			'last_error' => '',
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
	 * Is the site licensed? Includes the offline grace period.
	 * Third-party / premium behaviour should gate on the
	 * `etm_is_licensed` filter rather than calling this directly.
	 */
	public static function is_licensed() {
		$state    = self::get_state();
		$licensed = 'valid' === $state['status'];

		// Grace: an unreachable server within the window keeps the last
		// good status ("grace" is recorded by revalidate() on WP_Error).
		if ( 'grace' === $state['status'] ) {
			$licensed = ( time() - (int) $state['last_check'] ) < self::GRACE_DAYS * DAY_IN_SECONDS;
		}

		return (bool) apply_filters( 'etm_is_licensed', $licensed, $state );
	}

	/* -------------------------------------------------------- API calls */

	/**
	 * POST to the licensing API.
	 *
	 * @param string $action activate|deactivate|check
	 * @param string $key    License key.
	 * @return array|WP_Error Decoded response body.
	 */
	protected static function request( $action, $key ) {
		$response = wp_remote_post(
			self::api_url(),
			array(
				'timeout' => 15,
				'body'    => array(
					'action'  => $action,
					'key'     => $key,
					'site'    => home_url( '/' ),
					'product' => 'emotio-team',
					'version' => ETM_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new WP_Error(
				'etm_license_http',
				sprintf( /* translators: %d: HTTP status code */ __( 'Unexpected response from the licensing server (HTTP %d).', 'emotio-team' ), $code )
			);
		}

		return $body;
	}

	/**
	 * Activate a key for this site.
	 *
	 * @return true|WP_Error
	 */
	public static function activate( $key ) {
		$key = sanitize_text_field( $key );
		if ( ! $key ) {
			return new WP_Error( 'etm_license_empty', __( 'Please enter a license key.', 'emotio-team' ) );
		}

		$body = self::request( 'activate', $key );

		if ( is_wp_error( $body ) ) {
			self::save_state(
				array(
					'key'        => $key,
					'status'     => 'grace',
					'last_check' => time(),
					'last_error' => $body->get_error_message(),
				)
			);
			return $body;
		}

		$valid = ! empty( $body['success'] ) && ( $body['status'] ?? '' ) === 'valid';
		self::save_state(
			array(
				'key'        => $key,
				'status'     => $valid ? 'valid' : ( ( $body['status'] ?? '' ) === 'expired' ? 'expired' : 'invalid' ),
				'expires'    => sanitize_text_field( $body['expires'] ?? '' ),
				'customer'   => sanitize_text_field( $body['customer'] ?? '' ),
				'last_check' => time(),
				'last_error' => $valid ? '' : sanitize_text_field( $body['message'] ?? __( 'The license key was not accepted.', 'emotio-team' ) ),
			)
		);

		if ( ! $valid ) {
			return new WP_Error( 'etm_license_invalid', self::get_state( 'last_error' ) );
		}
		return true;
	}

	/**
	 * Release this site from the key.
	 *
	 * @return true|WP_Error
	 */
	public static function deactivate() {
		$key = self::get_state( 'key' );
		if ( $key ) {
			$result = self::request( 'deactivate', $key );
			// A failed remote deactivation still clears locally — the user
			// asked to disconnect and must never be trapped by a dead API.
			if ( is_wp_error( $result ) ) {
				$result = null;
			}
		}
		self::save_state( self::default_state() );
		return true;
	}

	/**
	 * Daily cron re-validation.
	 */
	public static function revalidate() {
		$key = self::get_state( 'key' );
		if ( ! $key ) {
			return;
		}

		$body = self::request( 'check', $key );

		if ( is_wp_error( $body ) ) {
			// Server unreachable: enter/stay in grace, keep the original
			// last_check so the grace window isn't endlessly refreshed.
			$changes = array( 'last_error' => $body->get_error_message() );
			if ( 'grace' !== self::get_state( 'status' ) ) {
				$changes['status']     = 'grace';
				$changes['last_check'] = time();
			}
			self::save_state( $changes );
			return;
		}

		$status = ( $body['status'] ?? '' );
		self::save_state(
			array(
				'status'     => in_array( $status, array( 'valid', 'expired', 'invalid' ), true ) ? $status : 'invalid',
				'expires'    => sanitize_text_field( $body['expires'] ?? self::get_state( 'expires' ) ),
				'customer'   => sanitize_text_field( $body['customer'] ?? self::get_state( 'customer' ) ),
				'last_check' => time(),
				'last_error' => '',
			)
		);
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

	/**
	 * Handle activate/deactivate/re-check form submissions.
	 */
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
		if ( strlen( $key ) <= 8 ) {
			return $key ? str_repeat( '•', strlen( $key ) ) : '';
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', max( 4, strlen( $key ) - 8 ) ) . substr( $key, -4 );
	}

	protected static function status_label() {
		$state = self::get_state();
		switch ( $state['status'] ) {
			case 'valid':
				return array( __( 'Active', 'emotio-team' ), '#00a32a' );
			case 'grace':
				return self::is_licensed()
					? array( __( 'Active (licensing server unreachable — grace period)', 'emotio-team' ), '#dba617' )
					: array( __( 'Grace period expired — please re-check', 'emotio-team' ), '#d63638' );
			case 'expired':
				return array( __( 'Expired', 'emotio-team' ), '#d63638' );
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

		$notice = isset( $_GET['etm_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['etm_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Emotio License', 'emotio-team' ); ?></h1>

			<?php if ( 'activated' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License activated — thank you!', 'emotio-team' ); ?></p></div>
			<?php elseif ( 'deactivated' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'License deactivated for this site.', 'emotio-team' ); ?></p></div>
			<?php elseif ( 'rechecked' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'License status refreshed.', 'emotio-team' ); ?></p></div>
			<?php elseif ( 0 === strpos( $notice, 'error:' ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( substr( $notice, 6 ) ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:640px;">
				<h2 style="margin-top:0;">
					<?php esc_html_e( 'Status:', 'emotio-team' ); ?>
					<span style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $label ); ?></span>
				</h2>
				<table class="form-table" role="presentation">
					<?php if ( $state['customer'] ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Licensed to', 'emotio-team' ); ?></th><td><?php echo esc_html( $state['customer'] ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $state['expires'] ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Renews / expires', 'emotio-team' ); ?></th><td><?php echo esc_html( $state['expires'] ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $state['last_check'] ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Last checked', 'emotio-team' ); ?></th>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['last_check'] ) ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $state['last_error'] ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Last error', 'emotio-team' ); ?></th><td><code><?php echo esc_html( $state['last_error'] ); ?></code></td></tr>
					<?php endif; ?>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'etm_license' ); ?>
					<input type="hidden" name="action" value="etm_license">

					<p>
						<label for="etm-key"><strong><?php esc_html_e( 'License key', 'emotio-team' ); ?></strong></label><br>
						<input type="text" id="etm-key" name="etm_key" class="regular-text code"
							value="<?php echo esc_attr( self::masked_key() ); ?>"
							<?php echo ( $state['key'] || $locked ) ? 'readonly' : ''; ?>
							placeholder="EMO-XXXX-XXXX-XXXX-XXXX" autocomplete="off">
					</p>
					<?php if ( $locked ) : ?>
						<p class="description"><?php esc_html_e( 'This key is set in wp-config.php via ETM_LICENSE_KEY and cannot be edited here.', 'emotio-team' ); ?></p>
					<?php endif; ?>

					<p>
						<?php if ( ! $state['key'] ) : ?>
							<button type="submit" name="etm_do" value="activate" class="button button-primary"><?php esc_html_e( 'Activate', 'emotio-team' ); ?></button>
						<?php else : ?>
							<button type="submit" name="etm_do" value="recheck" class="button button-primary"><?php esc_html_e( 'Re-check status', 'emotio-team' ); ?></button>
							<?php if ( ! $locked ) : ?>
								<button type="submit" name="etm_do" value="deactivate" class="button"><?php esc_html_e( 'Deactivate this site', 'emotio-team' ); ?></button>
							<?php endif; ?>
						<?php endif; ?>
					</p>
				</form>

				<p class="description">
					<?php esc_html_e( 'Your key is in your Emotio welcome email. An active license unlocks support and one-click updates; the plugin keeps working on your site either way.', 'emotio-team' ); ?>
					<a href="https://emotio.co.uk" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage your license →', 'emotio-team' ); ?></a>
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
			$message = __( 'Your Emotio Team license has expired — renew to keep receiving updates and support.', 'emotio-team' );
		} elseif ( in_array( $state['status'], array( 'invalid', 'grace' ), true ) ) {
			$message = __( 'Your Emotio Team license needs attention.', 'emotio-team' );
		} else {
			$message = __( 'Activate your Emotio Team license to unlock updates and support.', 'emotio-team' );
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( $message ),
			esc_url( $url ),
			esc_html__( 'Open license settings', 'emotio-team' )
		);
	}
}
