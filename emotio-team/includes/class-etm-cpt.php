<?php
/**
 * Team Member post type + Department / Skills taxonomies.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_CPT {

	const POST_TYPE  = 'team_member';
	const TAX_DEPT   = 'team_department';
	const TAX_TAG    = 'team_tag';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	public static function register() {
		$slug           = ETM_Settings::get( 'archive_slug' );
		$enable_single  = (bool) ETM_Settings::get( 'enable_single' );
		$enable_archive = (bool) ETM_Settings::get( 'enable_archive' );

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Team', 'emotio-team' ),
					'singular_name'      => __( 'Team Member', 'emotio-team' ),
					'add_new'            => __( 'Add Member', 'emotio-team' ),
					'add_new_item'       => __( 'Add Team Member', 'emotio-team' ),
					'edit_item'          => __( 'Edit Team Member', 'emotio-team' ),
					'new_item'           => __( 'New Team Member', 'emotio-team' ),
					'view_item'          => __( 'View Profile', 'emotio-team' ),
					'search_items'       => __( 'Search team', 'emotio-team' ),
					'not_found'          => __( 'No team members found', 'emotio-team' ),
					'not_found_in_trash' => __( 'No team members in the bin', 'emotio-team' ),
					'featured_image'     => __( 'Profile photo', 'emotio-team' ),
					'set_featured_image' => __( 'Set profile photo', 'emotio-team' ),
				),
				'public'              => true,
				'publicly_queryable'  => $enable_single,
				'exclude_from_search' => ! $enable_single,
				'has_archive'         => $enable_archive ? $slug : false,
				'rewrite'             => array(
					'slug'       => $slug,
					'with_front' => false,
				),
				'menu_icon'           => 'dashicons-groups',
				'menu_position'       => 21,
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions' ),
				'show_in_rest'        => true,
			)
		);

		register_taxonomy(
			self::TAX_DEPT,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Departments', 'emotio-team' ),
					'singular_name' => __( 'Department', 'emotio-team' ),
					'add_new_item'  => __( 'Add Department', 'emotio-team' ),
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => $slug . '-department' ),
			)
		);

		register_taxonomy(
			self::TAX_TAG,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Skills / Tags', 'emotio-team' ),
					'singular_name' => __( 'Skill / Tag', 'emotio-team' ),
					'add_new_item'  => __( 'Add Skill / Tag', 'emotio-team' ),
				),
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => $slug . '-tag' ),
			)
		);
	}

	/**
	 * Flush rewrites once after settings that affect URLs change.
	 */
	public static function maybe_flush() {
		if ( get_option( 'etm_flush_needed' ) ) {
			flush_rewrite_rules();
			delete_option( 'etm_flush_needed' );
		}
	}
}
