<?php
/**
 * Product registry — the products this server licenses.
 * Emotio Team and AI Schema Pro ship registered; add more on the
 * settings screen or via the `els_products` filter.
 *
 * @package Emotio_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ELS_Products {

	const OPTION = 'els_products';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
	}

	public static function defaults() {
		return array(
			'emotio-team'   => 'Emotio Team',
			'ai-schema-pro' => 'AI Schema Pro',
		);
	}

	/**
	 * All registered products as slug => label.
	 */
	public static function all() {
		$stored = (array) get_option( self::OPTION, array() );
		return apply_filters( 'els_products', array_merge( self::defaults(), $stored ) );
	}

	public static function exists( $slug ) {
		$products = self::all();
		return isset( $products[ $slug ] );
	}

	public static function label( $slug ) {
		$products = self::all();
		return isset( $products[ $slug ] ) ? $products[ $slug ] : $slug;
	}

	public static function register_setting() {
		register_setting(
			'els_products_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/**
	 * Settings textarea format: one "slug | Label" per line.
	 */
	public static function sanitize( $input ) {
		if ( is_array( $input ) && isset( $input['raw'] ) ) {
			$input = $input['raw'];
		}
		$out = array();
		foreach ( explode( "\n", (string) $input ) as $line ) {
			$line = trim( $line );
			if ( ! $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$slug  = sanitize_title( $parts[0] );
			if ( $slug ) {
				$out[ $slug ] = sanitize_text_field( isset( $parts[1] ) && $parts[1] ? $parts[1] : $parts[0] );
			}
		}
		return $out;
	}

	public static function add_page() {
		add_submenu_page(
			'edit.php?post_type=emotio_license',
			__( 'Products', 'emotio-license-server' ),
			__( 'Products', 'emotio-license-server' ),
			'manage_options',
			'els-products',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		$stored = (array) get_option( self::OPTION, array() );
		$lines  = array();
		foreach ( $stored as $slug => $label ) {
			$lines[] = $slug . ' | ' . $label;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Licensed Products', 'emotio-license-server' ); ?></h1>
			<p><?php esc_html_e( 'Built in and always available:', 'emotio-license-server' ); ?>
				<?php foreach ( self::defaults() as $slug => $label ) : ?>
					<code><?php echo esc_html( $slug ); ?></code> (<?php echo esc_html( $label ); ?>)
				<?php endforeach; ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'els_products_group' ); ?>
				<p><label for="els-products-raw"><strong><?php esc_html_e( 'Additional products', 'emotio-license-server' ); ?></strong></label></p>
				<p class="description"><?php esc_html_e( 'One per line as "slug | Display name". The slug must match the "product" value the client plugin sends.', 'emotio-license-server' ); ?></p>
				<textarea id="els-products-raw" name="<?php echo esc_attr( self::OPTION ); ?>[raw]" rows="6" class="large-text code"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
