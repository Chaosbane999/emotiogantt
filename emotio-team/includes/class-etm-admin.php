<?php
/**
 * Admin experience: portfolio-style list with photos, meta-aware search,
 * drag-and-drop ordering and one-click duplication.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Admin {

	public static function init() {
		add_filter( 'manage_team_member_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_team_member_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-team_member_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'default_order_and_sorting' ) );
		add_filter( 'posts_search', array( __CLASS__, 'search_meta_too' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_etm_save_order', array( __CLASS__, 'save_order' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_etm_duplicate', array( __CLASS__, 'duplicate' ) );
		add_action( 'admin_notices', array( __CLASS__, 'order_hint' ) );
	}

	public static function columns( $columns ) {
		$new = array(
			'cb'             => $columns['cb'],
			'etm_photo'      => __( 'Photo', 'emotio-team' ),
			'title'          => __( 'Name', 'emotio-team' ),
			'etm_job_title'  => __( 'Job title', 'emotio-team' ),
			'etm_email'      => __( 'Email', 'emotio-team' ),
			'taxonomy-team_department' => __( 'Department', 'emotio-team' ),
			'etm_featured'   => '★',
			'etm_id'         => __( 'ID', 'emotio-team' ),
			'etm_order'      => __( 'Order', 'emotio-team' ),
			'date'           => $columns['date'],
		);
		return $new;
	}

	public static function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'etm_photo':
				if ( has_post_thumbnail( $post_id ) ) {
					echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . get_the_post_thumbnail( $post_id, array( 48, 48 ), array( 'style' => 'border-radius:50%;object-fit:cover;width:48px;height:48px;' ) ) . '</a>';
				} else {
					echo '<span class="dashicons dashicons-admin-users" style="font-size:32px;color:#c3c4c7;"></span>';
				}
				break;
			case 'etm_job_title':
				echo esc_html( ETM_Meta::get( $post_id, 'job_title' ) );
				break;
			case 'etm_email':
				$email = ETM_Meta::get( $post_id, 'email' );
				if ( $email ) {
					echo '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
				}
				break;
			case 'etm_featured':
				echo ETM_Meta::get( $post_id, 'featured' ) ? '<span style="color:#dba617;">★</span>' : '';
				break;
			case 'etm_id':
				echo '<code title="' . esc_attr__( 'Use in triggers: class etm-profile-ID or link #etm-profile-ID', 'emotio-team' ) . '">' . esc_html( $post_id ) . '</code>';
				break;
			case 'etm_order':
				$post = get_post( $post_id );
				echo '<span class="etm-order-value">' . esc_html( $post->menu_order ) . '</span> <span class="dashicons dashicons-menu etm-drag-handle" title="' . esc_attr__( 'Drag to reorder', 'emotio-team' ) . '"></span>';
				break;
		}
	}

	public static function sortable_columns( $columns ) {
		$columns['etm_job_title'] = 'etm_job_title';
		$columns['etm_order']     = 'menu_order';
		return $columns;
	}

	/**
	 * Default the admin list to menu_order and support sorting by job title.
	 */
	public static function default_order_and_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ETM_CPT::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( ! $orderby ) {
			$query->set( 'orderby', 'menu_order title' );
			$query->set( 'order', 'ASC' );
		} elseif ( 'etm_job_title' === $orderby ) {
			$query->set( 'meta_key', '_etm_job_title' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Make the admin search also match job title, email, phone and location.
	 */
	public static function search_meta_too( $search, $query ) {
		global $wpdb;

		if ( ! is_admin() || empty( $search ) || ! $query->is_main_query()
			|| ETM_CPT::POST_TYPE !== $query->get( 'post_type' ) || ! $query->get( 's' ) ) {
			return $search;
		}

		$like = '%' . $wpdb->esc_like( $query->get( 's' ) ) . '%';
		$sub  = $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_etm_job_title','_etm_email','_etm_phone','_etm_location') AND meta_value LIKE %s",
			$like
		);

		// Append an OR that stays scoped to visible team members.
		$search .= " OR ({$wpdb->posts}.post_type = '" . ETM_CPT::POST_TYPE . "'"
			. " AND {$wpdb->posts}.post_status NOT IN ('trash','auto-draft')"
			. " AND {$wpdb->posts}.ID IN ($sub))";

		return $search;
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		$is_list = 'edit.php' === $hook && $screen && ETM_CPT::POST_TYPE === $screen->post_type;
		$is_edit = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && ETM_CPT::POST_TYPE === $screen->post_type;

		if ( ! $is_list && ! $is_edit ) {
			return;
		}

		wp_enqueue_style( 'etm-admin', ETM_URL . 'assets/css/admin.css', array(), ETM_VERSION );

		if ( $is_edit ) {
			wp_enqueue_media();
		}

		wp_enqueue_script(
			'etm-admin',
			ETM_URL . 'assets/js/admin.js',
			$is_list ? array( 'jquery', 'jquery-ui-sortable' ) : array( 'jquery' ),
			ETM_VERSION,
			true
		);
		wp_localize_script(
			'etm-admin',
			'etmAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'orderNonce' => wp_create_nonce( 'etm_save_order' ),
				'sortable'  => $is_list && self::is_menu_order_view(),
			)
		);
	}

	/**
	 * Drag ordering only makes sense when the list is shown in menu order.
	 */
	protected static function is_menu_order_view() {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : '';
		$search  = ! empty( $_GET['s'] );
		return ! $search && ( '' === $orderby || 'menu_order' === $orderby );
	}

	public static function order_hint() {
		$screen = get_current_screen();
		if ( $screen && 'edit-team_member' === $screen->id && self::is_menu_order_view() ) {
			echo '<div class="notice notice-info is-dismissible"><p>' .
				esc_html__( 'Tip: drag rows by the ≡ handle to reorder the team. The order is used everywhere “Custom order” is selected.', 'emotio-team' ) .
				'</p></div>';
		}
	}

	/**
	 * Persist drag-and-drop ordering.
	 */
	public static function save_order() {
		check_ajax_referer( 'etm_save_order', 'nonce' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$ids = isset( $_POST['order'] ) ? array_map( 'absint', (array) $_POST['order'] ) : array();
		$position = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		foreach ( $ids as $i => $id ) {
			if ( get_post_type( $id ) === ETM_CPT::POST_TYPE ) {
				wp_update_post(
					array(
						'ID'         => $id,
						'menu_order' => $position + $i,
					)
				);
			}
		}
		wp_send_json_success();
	}

	public static function row_actions( $actions, $post ) {
		if ( ETM_CPT::POST_TYPE === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin.php?action=etm_duplicate&post=' . $post->ID ),
				'etm_duplicate_' . $post->ID
			);
			$actions['etm_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'emotio-team' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Duplicate a member (post, meta, taxonomies) as a draft.
	 */
	public static function duplicate() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'etm_duplicate_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post || ETM_CPT::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this member.', 'emotio-team' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => $post->post_type,
				'post_title'   => sprintf( /* translators: %s: member name */ __( '%s (copy)', 'emotio-team' ), $post->post_title ),
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_status'  => 'draft',
				'menu_order'   => $post->menu_order + 1,
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}

		foreach ( array( ETM_CPT::TAX_DEPT, ETM_CPT::TAX_TAG ) as $tax ) {
			$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $new_id, $terms, $tax );
			}
		}

		$thumb = get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			set_post_thumbnail( $new_id, $thumb );
		}

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
		exit;
	}
}
