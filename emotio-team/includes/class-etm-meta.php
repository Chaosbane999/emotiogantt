<?php
/**
 * Team member profile fields: role, contact details, social links, hover photo.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Meta {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_boxes' ) );
		add_action( 'save_post_team_member', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
	}

	/**
	 * Social networks supported on profiles.
	 */
	public static function social_networks() {
		return array(
			'linkedin'  => 'LinkedIn',
			'twitter'   => 'X / Twitter',
			'instagram' => 'Instagram',
			'facebook'  => 'Facebook',
			'youtube'   => 'YouTube',
			'github'    => 'GitHub',
			'dribbble'  => 'Dribbble',
			'behance'   => 'Behance',
			'tiktok'    => 'TikTok',
			'website'   => __( 'Website', 'emotio-team' ),
		);
	}

	/**
	 * Plain text fields stored per member.
	 */
	public static function text_fields() {
		return array(
			'job_title' => __( 'Job title', 'emotio-team' ),
			'email'     => __( 'Email', 'emotio-team' ),
			'phone'     => __( 'Phone', 'emotio-team' ),
			'location'  => __( 'Location', 'emotio-team' ),
			'pronouns'  => __( 'Pronouns', 'emotio-team' ),
			'fun_fact'  => __( 'Fun fact', 'emotio-team' ),
		);
	}

	/**
	 * Convenience getter with the _etm_ prefix applied.
	 */
	public static function get( $post_id, $key ) {
		return get_post_meta( $post_id, '_etm_' . $key, true );
	}

	/**
	 * Custom profile field values for a member: array of
	 * [ 'key' => ..., 'label' => ..., 'value' => ... ], non-empty only.
	 */
	public static function custom_values( $post_id ) {
		$out = array();
		foreach ( ETM_Settings::custom_fields() as $key => $label ) {
			$value = get_post_meta( $post_id, '_etm_cf_' . $key, true );
			if ( '' !== $value && null !== $value ) {
				$out[] = array(
					'key'   => $key,
					'label' => $label,
					'value' => $value,
				);
			}
		}
		return $out;
	}

	/**
	 * All social links for a member, filtered to non-empty values.
	 */
	public static function socials( $post_id ) {
		$out = array();
		foreach ( self::social_networks() as $key => $label ) {
			$url = self::get( $post_id, 'social_' . $key );
			if ( $url ) {
				$out[ $key ] = array(
					'label' => $label,
					'url'   => $url,
				);
			}
		}
		return $out;
	}

	/**
	 * Expose fields to the REST API / block editor.
	 */
	public static function register_meta() {
		$keys = array_keys( self::text_fields() );
		foreach ( self::social_networks() as $key => $label ) {
			$keys[] = 'social_' . $key;
		}
		foreach ( $keys as $key ) {
			register_post_meta(
				ETM_CPT::POST_TYPE,
				'_etm_' . $key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
		register_post_meta(
			ETM_CPT::POST_TYPE,
			'_etm_hover_image_id',
			array(
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			ETM_CPT::POST_TYPE,
			'_etm_featured',
			array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	public static function add_boxes() {
		add_meta_box( 'etm-details', __( 'Member Details', 'emotio-team' ), array( __CLASS__, 'render_details' ), ETM_CPT::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'etm-extras', __( 'Photos & Visibility', 'emotio-team' ), array( __CLASS__, 'render_extras' ), ETM_CPT::POST_TYPE, 'side', 'default' );
	}

	public static function render_details( $post ) {
		wp_nonce_field( 'etm_save_meta', 'etm_meta_nonce' );
		?>
		<div class="etm-fields">
			<?php foreach ( self::text_fields() as $key => $label ) : ?>
				<p class="etm-field">
					<label for="etm-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
					<input type="text" class="widefat" id="etm-<?php echo esc_attr( $key ); ?>"
						name="etm[<?php echo esc_attr( $key ); ?>]"
						value="<?php echo esc_attr( self::get( $post->ID, $key ) ); ?>">
				</p>
			<?php endforeach; ?>
		</div>
		<h4><?php esc_html_e( 'Social profiles', 'emotio-team' ); ?></h4>
		<div class="etm-fields etm-fields--social">
			<?php foreach ( self::social_networks() as $key => $label ) : ?>
				<p class="etm-field">
					<label for="etm-social-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
					<input type="url" class="widefat" id="etm-social-<?php echo esc_attr( $key ); ?>"
						name="etm[social_<?php echo esc_attr( $key ); ?>]" placeholder="https://"
						value="<?php echo esc_attr( self::get( $post->ID, 'social_' . $key ) ); ?>">
				</p>
			<?php endforeach; ?>
		</div>
		<?php $custom = ETM_Settings::custom_fields(); ?>
		<?php if ( $custom ) : ?>
			<h4><?php esc_html_e( 'Additional details', 'emotio-team' ); ?></h4>
			<div class="etm-fields">
				<?php foreach ( $custom as $key => $label ) : ?>
					<p class="etm-field">
						<label for="etm-cf-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
						<input type="text" class="widefat" id="etm-cf-<?php echo esc_attr( $key ); ?>"
							name="etm[cf_<?php echo esc_attr( $key ); ?>]"
							value="<?php echo esc_attr( get_post_meta( $post->ID, '_etm_cf_' . $key, true ) ); ?>">
					</p>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'These fields are defined under Team → Settings → Custom profile fields and shown on profiles.', 'emotio-team' ); ?></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'The main editor above is the full biography; the excerpt is the short card intro. Both are optional.', 'emotio-team' ); ?></p>
		<?php
	}

	public static function render_extras( $post ) {
		$hover_id  = absint( self::get( $post->ID, 'hover_image_id' ) );
		$hover_src = $hover_id ? wp_get_attachment_image_url( $hover_id, 'medium' ) : '';
		$featured  = (bool) self::get( $post->ID, 'featured' );
		?>
		<p><strong><?php esc_html_e( 'Hover photo', 'emotio-team' ); ?></strong></p>
		<p class="description"><?php esc_html_e( 'Optional second photo shown on hover (used by the “Swap” hover effect).', 'emotio-team' ); ?></p>
		<div class="etm-hover-image">
			<img src="<?php echo esc_url( $hover_src ); ?>" alt="" style="max-width:100%;<?php echo $hover_src ? '' : 'display:none;'; ?>">
		</div>
		<input type="hidden" name="etm[hover_image_id]" class="etm-hover-image-id" value="<?php echo esc_attr( $hover_id ? $hover_id : '' ); ?>">
		<p>
			<button type="button" class="button etm-pick-hover"><?php esc_html_e( 'Choose photo', 'emotio-team' ); ?></button>
			<button type="button" class="button etm-remove-hover" <?php echo $hover_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'emotio-team' ); ?></button>
		</p>
		<hr>
		<p><label><input type="checkbox" name="etm[featured]" value="1" <?php checked( $featured ); ?>> <strong><?php esc_html_e( 'Featured member', 'emotio-team' ); ?></strong></label></p>
		<p class="description"><?php esc_html_e( 'Featured members get a star in the admin list and can be targeted with tag="featured" style queries in layouts.', 'emotio-team' ); ?></p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['etm_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['etm_meta_nonce'] ), 'etm_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$data = isset( $_POST['etm'] ) && is_array( $_POST['etm'] ) ? wp_unslash( $_POST['etm'] ) : array();

		foreach ( self::text_fields() as $key => $label ) {
			$value = isset( $data[ $key ] ) ? sanitize_text_field( $data[ $key ] ) : '';
			if ( 'email' === $key ) {
				$value = sanitize_email( $value );
			}
			self::update( $post_id, $key, $value );
		}

		foreach ( self::social_networks() as $key => $label ) {
			$value = isset( $data[ 'social_' . $key ] ) ? esc_url_raw( $data[ 'social_' . $key ] ) : '';
			self::update( $post_id, 'social_' . $key, $value );
		}

		foreach ( ETM_Settings::custom_fields() as $key => $label ) {
			$value = isset( $data[ 'cf_' . $key ] ) ? sanitize_text_field( $data[ 'cf_' . $key ] ) : '';
			self::update( $post_id, 'cf_' . $key, $value );
		}

		$hover = isset( $data['hover_image_id'] ) ? absint( $data['hover_image_id'] ) : 0;
		self::update( $post_id, 'hover_image_id', $hover ? $hover : '' );

		self::update( $post_id, 'featured', empty( $data['featured'] ) ? '' : 1 );
	}

	protected static function update( $post_id, $key, $value ) {
		if ( '' === $value || null === $value ) {
			delete_post_meta( $post_id, '_etm_' . $key );
		} else {
			update_post_meta( $post_id, '_etm_' . $key, $value );
		}
	}
}
