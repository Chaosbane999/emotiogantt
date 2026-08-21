<?php
/**
 * License records: an admin-only post type with key, product, customer,
 * seats, expiry, status and per-site activations.
 *
 * @package Emotio_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ELS_CPT {

	const POST_TYPE = 'emotio_license';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'admin_action_els_remove_activation', array( __CLASS__, 'remove_activation' ) );
		add_filter( 'posts_search', array( __CLASS__, 'search_keys_too' ), 10, 2 );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Licenses', 'emotio-license-server' ),
					'singular_name' => __( 'License', 'emotio-license-server' ),
					'add_new'       => __( 'Add License', 'emotio-license-server' ),
					'add_new_item'  => __( 'Add License', 'emotio-license-server' ),
					'edit_item'     => __( 'Edit License', 'emotio-license-server' ),
					'search_items'  => __( 'Search licenses', 'emotio-license-server' ),
					'not_found'     => __( 'No licenses found', 'emotio-license-server' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-privacy',
				'menu_position'   => 59,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/* ----------------------------------------------------------- lookup */

	/**
	 * Find a license post by key (and optionally product).
	 *
	 * @return WP_Post|null
	 */
	public static function find_by_key( $key, $product = '' ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => '_els_key',
					'value' => $key,
				),
			),
		);
		if ( $product ) {
			$args['meta_query'][] = array(
				'key'   => '_els_product',
				'value' => $product,
			);
		}
		$found = get_posts( $args );
		return $found ? $found[0] : null;
	}

	public static function meta( $post_id, $key ) {
		return get_post_meta( $post_id, '_els_' . $key, true );
	}

	public static function activations( $post_id ) {
		$activations = get_post_meta( $post_id, '_els_activations', true );
		return is_array( $activations ) ? $activations : array();
	}

	public static function save_activations( $post_id, array $activations ) {
		update_post_meta( $post_id, '_els_activations', array_values( $activations ) );
	}

	/* --------------------------------------------------------- admin UI */

	public static function boxes() {
		add_meta_box( 'els-license', __( 'License', 'emotio-license-server' ), array( __CLASS__, 'render_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'els-activations', __( 'Activations', 'emotio-license-server' ), array( __CLASS__, 'render_activations' ), self::POST_TYPE, 'normal', 'default' );
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'els_save', 'els_nonce' );
		$key     = self::meta( $post->ID, 'key' );
		$product = self::meta( $post->ID, 'product' );
		$email   = self::meta( $post->ID, 'email' );
		$seats   = self::meta( $post->ID, 'seats' );
		$expires = self::meta( $post->ID, 'expires' );
		$status  = self::meta( $post->ID, 'status' ) ?: 'active';
		?>
		<style>.els-grid{display:grid;grid-template-columns:160px 1fr;gap:12px 16px;align-items:center;max-width:640px}.els-grid label{font-weight:600}</style>
		<div class="els-grid">
			<label for="els-key"><?php esc_html_e( 'License key', 'emotio-license-server' ); ?></label>
			<span>
				<input type="text" id="els-key" name="els[key]" class="regular-text code" value="<?php echo esc_attr( $key ); ?>" placeholder="<?php esc_attr_e( 'Leave empty to auto-generate', 'emotio-license-server' ); ?>">
				<button type="button" class="button" onclick="var a='23456789ABCDEFGHJKMNPQRSTUVWXYZ',k='EMO';for(var g=0;g<4;g++){k+='-';for(var i=0;i<4;i++){k+=a[Math.floor(Math.random()*a.length)];}}document.getElementById('els-key').value=k;"><?php esc_html_e( 'Generate', 'emotio-license-server' ); ?></button>
			</span>

			<label for="els-product"><?php esc_html_e( 'Product', 'emotio-license-server' ); ?></label>
			<select id="els-product" name="els[product]">
				<?php foreach ( ELS_Products::all() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $product, $slug ); ?>><?php echo esc_html( $label ); ?> (<?php echo esc_html( $slug ); ?>)</option>
				<?php endforeach; ?>
			</select>

			<label for="els-email"><?php esc_html_e( 'Customer email', 'emotio-license-server' ); ?></label>
			<input type="email" id="els-email" name="els[email]" class="regular-text" value="<?php echo esc_attr( $email ); ?>">

			<label for="els-seats"><?php esc_html_e( 'Seats (sites)', 'emotio-license-server' ); ?></label>
			<span><input type="number" id="els-seats" name="els[seats]" min="0" value="<?php echo esc_attr( '' === $seats ? 1 : (int) $seats ); ?>" style="width:90px"> <span class="description"><?php esc_html_e( '0 = unlimited', 'emotio-license-server' ); ?></span></span>

			<label for="els-expires"><?php esc_html_e( 'Expires', 'emotio-license-server' ); ?></label>
			<span><input type="date" id="els-expires" name="els[expires]" value="<?php echo esc_attr( $expires ); ?>"> <span class="description"><?php esc_html_e( 'Empty = lifetime', 'emotio-license-server' ); ?></span></span>

			<label for="els-status"><?php esc_html_e( 'Status', 'emotio-license-server' ); ?></label>
			<select id="els-status" name="els[status]">
				<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'emotio-license-server' ); ?></option>
				<option value="disabled" <?php selected( $status, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'emotio-license-server' ); ?></option>
			</select>
		</div>
		<p class="description" style="margin-top:12px;"><?php esc_html_e( 'The post title above is the customer name shown inside client plugins ("Licensed to").', 'emotio-license-server' ); ?></p>
		<?php
	}

	public static function render_activations( $post ) {
		$activations = self::activations( $post->ID );
		if ( ! $activations ) {
			echo '<p>' . esc_html__( 'No sites activated yet.', 'emotio-license-server' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Site', 'emotio-license-server' ) . '</th><th>' . esc_html__( 'Activated', 'emotio-license-server' ) . '</th><th>' . esc_html__( 'Last check', 'emotio-license-server' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $activations as $activation ) {
			$remove = wp_nonce_url(
				admin_url( 'admin.php?action=els_remove_activation&license=' . $post->ID . '&site=' . rawurlencode( $activation['site'] ) ),
				'els_remove_' . $post->ID
			);
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td><a href="%s" class="button button-small">%s</a></td></tr>',
				esc_html( $activation['site'] ),
				esc_html( wp_date( get_option( 'date_format' ), (int) $activation['time'] ) ),
				! empty( $activation['last_check'] ) ? esc_html( wp_date( get_option( 'date_format' ), (int) $activation['last_check'] ) ) : '—',
				esc_url( $remove ),
				esc_html__( 'Remove', 'emotio-license-server' )
			);
		}
		echo '</tbody></table>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['els_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['els_nonce'] ), 'els_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$data = isset( $_POST['els'] ) && is_array( $_POST['els'] ) ? wp_unslash( $_POST['els'] ) : array();

		$key = strtoupper( sanitize_text_field( $data['key'] ?? '' ) );
		if ( ! $key ) {
			$key = els_generate_key();
		}

		$product = sanitize_title( $data['product'] ?? '' );
		if ( ! ELS_Products::exists( $product ) ) {
			$product = 'emotio-team';
		}

		update_post_meta( $post_id, '_els_key', $key );
		update_post_meta( $post_id, '_els_product', $product );
		update_post_meta( $post_id, '_els_email', sanitize_email( $data['email'] ?? '' ) );
		update_post_meta( $post_id, '_els_seats', max( 0, absint( $data['seats'] ?? 1 ) ) );
		update_post_meta( $post_id, '_els_status', ( $data['status'] ?? '' ) === 'disabled' ? 'disabled' : 'active' );

		$expires = sanitize_text_field( $data['expires'] ?? '' );
		update_post_meta( $post_id, '_els_expires', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ? $expires : '' );
	}

	public static function remove_activation() {
		$post_id = isset( $_GET['license'] ) ? absint( $_GET['license'] ) : 0;
		check_admin_referer( 'els_remove_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'emotio-license-server' ) );
		}

		$site        = isset( $_GET['site'] ) ? els_normalize_site( rawurldecode( wp_unslash( $_GET['site'] ) ) ) : '';
		$activations = array_filter(
			self::activations( $post_id ),
			function ( $activation ) use ( $site ) {
				return $activation['site'] !== $site;
			}
		);
		self::save_activations( $post_id, $activations );

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $post_id ) );
		exit;
	}

	/* ------------------------------------------------------ list table */

	public static function columns( $columns ) {
		return array(
			'cb'          => $columns['cb'],
			'title'       => __( 'Customer', 'emotio-license-server' ),
			'els_key'     => __( 'Key', 'emotio-license-server' ),
			'els_product' => __( 'Product', 'emotio-license-server' ),
			'els_seats'   => __( 'Seats', 'emotio-license-server' ),
			'els_expires' => __( 'Expires', 'emotio-license-server' ),
			'els_status'  => __( 'Status', 'emotio-license-server' ),
			'date'        => $columns['date'],
		);
	}

	public static function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'els_key':
				echo '<code>' . esc_html( self::meta( $post_id, 'key' ) ) . '</code>';
				break;
			case 'els_product':
				echo esc_html( ELS_Products::label( self::meta( $post_id, 'product' ) ) );
				break;
			case 'els_seats':
				$seats = (int) self::meta( $post_id, 'seats' );
				echo esc_html( count( self::activations( $post_id ) ) . ' / ' . ( $seats ? $seats : '∞' ) );
				break;
			case 'els_expires':
				$expires = self::meta( $post_id, 'expires' );
				if ( ! $expires ) {
					esc_html_e( 'Lifetime', 'emotio-license-server' );
				} elseif ( $expires < gmdate( 'Y-m-d' ) ) {
					echo '<span style="color:#d63638;">' . esc_html( $expires ) . '</span>';
				} else {
					echo esc_html( $expires );
				}
				break;
			case 'els_status':
				$status = self::meta( $post_id, 'status' ) ?: 'active';
				printf(
					'<span style="color:%s;">%s</span>',
					'active' === $status ? '#00a32a' : '#d63638',
					'active' === $status ? esc_html__( 'Active', 'emotio-license-server' ) : esc_html__( 'Disabled', 'emotio-license-server' )
				);
				break;
		}
	}

	/**
	 * Let the admin search box find licenses by key or customer email.
	 */
	public static function search_keys_too( $search, $query ) {
		global $wpdb;

		if ( ! is_admin() || empty( $search ) || ! $query->is_main_query()
			|| self::POST_TYPE !== $query->get( 'post_type' ) || ! $query->get( 's' ) ) {
			return $search;
		}

		$like = '%' . $wpdb->esc_like( $query->get( 's' ) ) . '%';
		$sub  = $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_els_key','_els_email') AND meta_value LIKE %s",
			$like
		);

		$search .= " OR ({$wpdb->posts}.post_type = '" . self::POST_TYPE . "'"
			. " AND {$wpdb->posts}.post_status NOT IN ('trash','auto-draft')"
			. " AND {$wpdb->posts}.ID IN ($sub))";

		return $search;
	}
}
