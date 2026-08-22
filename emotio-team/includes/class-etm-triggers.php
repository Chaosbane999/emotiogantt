<?php
/**
 * Profile triggers — open any team member's profile modal / slide-out
 * panel from ANY element on a Salient site.
 *
 * Three ways in:
 *
 * 1. Salient's own builder elements (Button, Single Image, Icon) gain a
 *    "Team profile" dropdown via vc_add_param. Pick a member + modal or
 *    panel, and clicking that element opens their profile.
 *
 * 2. Any element, no builder integration needed:
 *    - give it the class  etm-profile-{id-or-slug}  (modal)
 *      or                 etm-panel-{id-or-slug}    (panel)
 *    - or link it to      #etm-profile-{id-or-slug} / #etm-panel-{id-or-slug}
 *
 * 3. Profile HTML is served by a public AJAX endpoint (published members
 *    only), so triggers work on pages with no team layout at all.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Triggers {

	/** Salient / WPBakery elements that get the Team profile params. */
	protected static function target_elements() {
		return apply_filters( 'etm_trigger_elements', array( 'nectar_btn', 'vc_single_image', 'vc_icon', 'nectar_icon', 'nectar_cta' ) );
	}

	public static function init() {
		add_action( 'wp_ajax_etm_profile', array( __CLASS__, 'ajax_profile' ) );
		add_action( 'wp_ajax_nopriv_etm_profile', array( __CLASS__, 'ajax_profile' ) );
		add_action( 'vc_after_init', array( __CLASS__, 'add_vc_params' ) );
		add_filter( 'vc_shortcode_output', array( __CLASS__, 'wrap_vc_output' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_globally' ), 20 );
	}

	/**
	 * Class/anchor triggers can sit on any page, so the (small) front-end
	 * assets load site-wide unless switched off in settings.
	 */
	public static function maybe_enqueue_globally() {
		if ( ETM_Settings::get( 'global_triggers' ) ) {
			ETM_Shortcode::enqueue_assets();
		}
	}

	/**
	 * Serve a member's profile HTML (published members only).
	 * Accepts a post ID or slug via ?member=.
	 */
	public static function ajax_profile() {
		$key  = isset( $_GET['member'] ) ? sanitize_text_field( wp_unslash( $_GET['member'] ) ) : '';
		$post = null;

		if ( is_numeric( $key ) ) {
			$post = get_post( (int) $key );
		} elseif ( $key ) {
			$found = get_posts(
				array(
					'post_type'      => ETM_CPT::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'name'           => sanitize_title( $key ),
				)
			);
			$post = $found ? $found[0] : null;
		}

		if ( ! $post || ETM_CPT::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			wp_send_json_error( array( 'message' => 'not_found' ), 404 );
		}

		try {
			$html = ETM_Shortcode::detail_html( $post );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => 'render_failed' ), 500 );
		}
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Members as a WPBakery dropdown (value list).
	 */
	public static function members_dropdown() {
		$options = array( __( '— None —', 'emotio-team' ) => '' );
		$members = get_posts(
			array(
				'post_type'      => ETM_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $members as $member ) {
			$options[ $member->post_title . ' (#' . $member->ID . ')' ] = (string) $member->ID;
		}
		return $options;
	}

	/**
	 * Add "Team profile" params to Salient's own elements.
	 */
	public static function add_vc_params() {
		if ( ! function_exists( 'vc_add_param' ) ) {
			return;
		}
		$members = self::members_dropdown();

		foreach ( self::target_elements() as $element ) {
			vc_add_param(
				$element,
				array(
					'type'        => 'dropdown',
					'heading'     => __( 'Open team profile on click', 'emotio-team' ),
					'param_name'  => 'etm_profile',
					'value'       => $members,
					'group'       => __( 'Team Profile', 'emotio-team' ),
					'description' => __( 'Added by Emotio Team: clicking this element opens the selected member\'s profile.', 'emotio-team' ),
				)
			);
			vc_add_param(
				$element,
				array(
					'type'       => 'dropdown',
					'heading'    => __( 'Open as', 'emotio-team' ),
					'param_name' => 'etm_profile_mode',
					'value'      => array(
						__( 'Profile modal', 'emotio-team' )     => 'modal',
						__( 'Slide-out panel', 'emotio-team' )   => 'panel',
					),
					'group'      => __( 'Team Profile', 'emotio-team' ),
					'dependency' => array(
						'element'   => 'etm_profile',
						'not_empty' => true,
					),
				)
			);
		}
	}

	/**
	 * When a Salient element carries etm_profile, wrap its output in a
	 * click trigger (display:contents keeps layout untouched).
	 */
	public static function wrap_vc_output( $output, $obj, $atts, $tag = '' ) {
		if ( empty( $atts['etm_profile'] ) || ! in_array( $tag, self::target_elements(), true ) ) {
			return $output;
		}
		$member = absint( $atts['etm_profile'] );
		if ( ! $member ) {
			return $output;
		}
		$mode = isset( $atts['etm_profile_mode'] ) && 'panel' === $atts['etm_profile_mode'] ? 'panel' : 'modal';

		ETM_Shortcode::enqueue_assets();

		return '<span class="etm-vc-trigger" data-etm-profile="' . esc_attr( $member ) . '" data-etm-mode="' . esc_attr( $mode ) . '" role="button" tabindex="0">' . $output . '</span>';
	}
}
