<?php
/**
 * Emotio License Client — reusable drop-in.
 *
 * Copy this single file into any Emotio product (AI Schema Pro, future
 * plugins) and instantiate it to get the full Emotio licensing experience:
 * an admin License screen, activation/deactivation, daily re-validation,
 * a 14-day offline grace period, and an is-licensed filter to gate
 * premium behaviour. Talks to the Emotio License Server REST API.
 *
 * Example (AI Schema Pro):
 *
 *   require_once __DIR__ . '/class-emotio-license-client.php';
 *   new Emotio_License_Client( array(
 *       'product'     => 'ai-schema-pro',
 *       'label'       => 'AI Schema Pro',
 *       'option'      => 'aisp_license',
 *       'menu_parent' => 'options-general.php',   // or your plugin's menu slug
 *       'version'     => AISP_VERSION,
 *   ) );
 *
 *   // Gate premium behaviour anywhere:
 *   if ( apply_filters( 'emotio_is_licensed_ai-schema-pro', false ) ) { ... }
 *
 * @package Emotio_License
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Emotio_License_Client' ) ) {
	return;
}

class Emotio_License_Client {

	const GRACE_DAYS = 14;

	/** @var array Resolved configuration. */
	protected $config;

	/** @var array|null Cached license state. */
	protected $state = null;

	/**
	 * @param array $config {
	 *   @type string $product     Product slug registered on the license server (required).
	 *   @type string $label       Human product name shown in the UI.
	 *   @type string $option      Option name to store state in. Default 'emotio_license_{product}'.
	 *   @type string $menu_parent Admin parent slug for the License page. Default 'options-general.php'.
	 *   @type string $version     Product version reported to the server.
	 *   @type string $api_url     Override the licensing endpoint.
	 *   @type string $constant    wp-config constant that pre-seeds the key.
	 * }
	 */
	public function __construct( array $config ) {
		$product      = sanitize_title( $config['product'] ?? '' );
		$this->config = wp_parse_args(
			$config,
			array(
				'product'     => $product,
				'label'       => ucwords( str_replace( '-', ' ', $product ) ),
				'option'      => 'emotio_license_' . str_replace( '-', '_', $product ),
				'menu_parent' => 'options-general.php',
				'version'     => '1.0.0',
				'api_url'     => 'https://licensing.emotio.co.uk/wp-json/emotio/v1/license',
				'constant'    => 'EMOTIO_LICENSE_KEY_' . strtoupper( str_replace( '-', '_', $product ) ),
			)
		);

		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_emotio_license_' . $this->config['product'], array( $this, 'handle_form' ) );
		add_action( $this->cron_hook(), array( $this, 'revalidate' ) );
		add_filter( 'emotio_is_licensed_' . $this->config['product'], array( $this, 'filter_is_licensed' ) );

		if ( ! wp_next_scheduled( $this->cron_hook() ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $this->cron_hook() );
		}

		$constant = $this->config['constant'];
		if ( defined( $constant ) && constant( $constant ) && constant( $constant ) !== $this->get_state( 'key' ) ) {
			$this->activate( constant( $constant ) );
		}
	}

	protected function cron_hook() {
		return 'emotio_license_check_' . str_replace( '-', '_', $this->config['product'] );
	}

	/**
	 * Call from your product's deactivation hook.
	 */
	public function unschedule() {
		$timestamp = wp_next_scheduled( $this->cron_hook() );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $this->cron_hook() );
		}
	}

	/* ------------------------------------------------------------ state */

	protected function default_state() {
		return array(
			'key'        => '',
			'status'     => 'inactive',
			'expires'    => '',
			'customer'   => '',
			'last_check' => 0,
			'last_error' => '',
		);
	}

	public function get_state( $field = null ) {
		if ( null === $this->state ) {
			$this->state = wp_parse_args( (array) get_option( $this->config['option'], array() ), $this->default_state() );
		}
		if ( null === $field ) {
			return $this->state;
		}
		return isset( $this->state[ $field ] ) ? $this->state[ $field ] : null;
	}

	protected function save_state( array $changes ) {
		$this->state = array_merge( $this->get_state(), $changes );
		update_option( $this->config['option'], $this->state, false );
	}

	public function is_licensed() {
		$state    = $this->get_state();
		$licensed = 'valid' === $state['status'];
		if ( 'grace' === $state['status'] ) {
			$licensed = ( time() - (int) $state['last_check'] ) < self::GRACE_DAYS * DAY_IN_SECONDS;
		}
		return $licensed;
	}

	public function filter_is_licensed( $default ) {
		return $this->is_licensed();
	}

	/* -------------------------------------------------------- API calls */

	protected function request( $action, $key ) {
		$response = wp_remote_post(
			apply_filters( 'emotio_license_api_url', $this->config['api_url'], $this->config['product'] ),
			array(
				'timeout' => 15,
				'body'    => array(
					'action'  => $action,
					'key'     => $key,
					'site'    => home_url( '/' ),
					'product' => $this->config['product'],
					'version' => $this->config['version'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new WP_Error( 'emotio_license_http', sprintf( 'Unexpected response from the licensing server (HTTP %d).', $code ) );
		}
		return $body;
	}

	public function activate( $key ) {
		$key = sanitize_text_field( $key );
		if ( ! $key ) {
			return new WP_Error( 'emotio_license_empty', __( 'Please enter a license key.', 'emotio-team' ) );
		}

		$body = $this->request( 'activate', $key );

		if ( is_wp_error( $body ) ) {
			$this->save_state(
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
		$this->save_state(
			array(
				'key'        => $key,
				'status'     => $valid ? 'valid' : ( ( $body['status'] ?? '' ) === 'expired' ? 'expired' : 'invalid' ),
				'expires'    => sanitize_text_field( $body['expires'] ?? '' ),
				'customer'   => sanitize_text_field( $body['customer'] ?? '' ),
				'last_check' => time(),
				'last_error' => $valid ? '' : sanitize_text_field( $body['message'] ?? 'The license key was not accepted.' ),
			)
		);

		return $valid ? true : new WP_Error( 'emotio_license_invalid', $this->get_state( 'last_error' ) );
	}

	public function deactivate() {
		$key = $this->get_state( 'key' );
		if ( $key ) {
			$this->request( 'deactivate', $key );
		}
		$this->save_state( $this->default_state() );
		return true;
	}

	public function revalidate() {
		$key = $this->get_state( 'key' );
		if ( ! $key ) {
			return;
		}

		$body = $this->request( 'check', $key );

		if ( is_wp_error( $body ) ) {
			$changes = array( 'last_error' => $body->get_error_message() );
			if ( 'grace' !== $this->get_state( 'status' ) ) {
				$changes['status']     = 'grace';
				$changes['last_check'] = time();
			}
			$this->save_state( $changes );
			return;
		}

		$status = $body['status'] ?? '';
		$this->save_state(
			array(
				'status'     => in_array( $status, array( 'valid', 'expired', 'invalid' ), true ) ? $status : 'invalid',
				'expires'    => sanitize_text_field( $body['expires'] ?? $this->get_state( 'expires' ) ),
				'customer'   => sanitize_text_field( $body['customer'] ?? $this->get_state( 'customer' ) ),
				'last_check' => time(),
				'last_error' => '',
			)
		);
	}

	/* --------------------------------------------------------- admin UI */

	public function add_page() {
		add_submenu_page(
			$this->config['menu_parent'],
			sprintf( '%s — License', $this->config['label'] ),
			'License',
			'manage_options',
			'emotio-license-' . $this->config['product'],
			array( $this, 'render_page' )
		);
	}

	public function handle_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'emotio_license_' . $this->config['product'] );

		$do     = isset( $_POST['emotio_do'] ) ? sanitize_key( $_POST['emotio_do'] ) : '';
		$notice = '';

		if ( 'activate' === $do ) {
			$key    = isset( $_POST['emotio_key'] ) ? sanitize_text_field( wp_unslash( $_POST['emotio_key'] ) ) : '';
			$result = $this->activate( $key );
			$notice = is_wp_error( $result ) ? 'error:' . $result->get_error_message() : 'activated';
		} elseif ( 'deactivate' === $do ) {
			$this->deactivate();
			$notice = 'deactivated';
		} elseif ( 'recheck' === $do ) {
			$this->revalidate();
			$notice = 'rechecked';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'emotio-license-' . $this->config['product'],
					'emotio_notice' => rawurlencode( $notice ),
				),
				admin_url( $this->config['menu_parent'] )
			)
		);
		exit;
	}

	protected function masked_key() {
		$key = $this->get_state( 'key' );
		if ( strlen( $key ) <= 8 ) {
			return $key ? str_repeat( '•', strlen( $key ) ) : '';
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', max( 4, strlen( $key ) - 8 ) ) . substr( $key, -4 );
	}

	public function render_page() {
		$state  = $this->get_state();
		$locked = defined( $this->config['constant'] ) && constant( $this->config['constant'] );

		$labels = array(
			'valid'    => array( 'Active', '#00a32a' ),
			'grace'    => $this->is_licensed()
				? array( 'Active (licensing server unreachable — grace period)', '#dba617' )
				: array( 'Grace period expired — please re-check', '#d63638' ),
			'expired'  => array( 'Expired', '#d63638' ),
			'invalid'  => array( 'Invalid', '#d63638' ),
			'inactive' => array( 'Not activated', '#646970' ),
		);
		list( $label, $color ) = isset( $labels[ $state['status'] ] ) ? $labels[ $state['status'] ] : $labels['inactive'];

		$notice = isset( $_GET['emotio_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['emotio_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->config['label'] ); ?> — License</h1>

			<?php if ( 'activated' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p>License activated — thank you!</p></div>
			<?php elseif ( 'deactivated' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p>License deactivated for this site.</p></div>
			<?php elseif ( 'rechecked' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p>License status refreshed.</p></div>
			<?php elseif ( 0 === strpos( $notice, 'error:' ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( substr( $notice, 6 ) ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:640px;">
				<h2 style="margin-top:0;">Status: <span style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $label ); ?></span></h2>
				<table class="form-table" role="presentation">
					<?php if ( $state['customer'] ) : ?><tr><th scope="row">Licensed to</th><td><?php echo esc_html( $state['customer'] ); ?></td></tr><?php endif; ?>
					<?php if ( $state['expires'] ) : ?><tr><th scope="row">Renews / expires</th><td><?php echo esc_html( $state['expires'] ); ?></td></tr><?php endif; ?>
					<?php if ( $state['last_check'] ) : ?><tr><th scope="row">Last checked</th><td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['last_check'] ) ); ?></td></tr><?php endif; ?>
					<?php if ( $state['last_error'] ) : ?><tr><th scope="row">Last error</th><td><code><?php echo esc_html( $state['last_error'] ); ?></code></td></tr><?php endif; ?>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'emotio_license_' . $this->config['product'] ); ?>
					<input type="hidden" name="action" value="emotio_license_<?php echo esc_attr( $this->config['product'] ); ?>">

					<p>
						<label for="emotio-key-<?php echo esc_attr( $this->config['product'] ); ?>"><strong>License key</strong></label><br>
						<input type="text" id="emotio-key-<?php echo esc_attr( $this->config['product'] ); ?>" name="emotio_key" class="regular-text code"
							value="<?php echo esc_attr( $this->masked_key() ); ?>"
							<?php echo ( $state['key'] || $locked ) ? 'readonly' : ''; ?>
							placeholder="EMO-XXXX-XXXX-XXXX-XXXX" autocomplete="off">
					</p>
					<?php if ( $locked ) : ?>
						<p class="description">This key is set in wp-config.php via <?php echo esc_html( $this->config['constant'] ); ?> and cannot be edited here.</p>
					<?php endif; ?>

					<p>
						<?php if ( ! $state['key'] ) : ?>
							<button type="submit" name="emotio_do" value="activate" class="button button-primary">Activate</button>
						<?php else : ?>
							<button type="submit" name="emotio_do" value="recheck" class="button button-primary">Re-check status</button>
							<?php if ( ! $locked ) : ?>
								<button type="submit" name="emotio_do" value="deactivate" class="button">Deactivate this site</button>
							<?php endif; ?>
						<?php endif; ?>
					</p>
				</form>

				<p class="description">Your key is in your Emotio welcome email. <a href="https://emotio.co.uk" target="_blank" rel="noopener noreferrer">Manage your license →</a></p>
			</div>
		</div>
		<?php
	}
}
