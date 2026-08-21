<?php
/**
 * WPBakery Page Builder element — Salient bundles WPBakery, so the team
 * layouts drop straight into the Salient page builder as a native element.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_WPBakery {

	public static function init() {
		add_action( 'vc_before_init', array( __CLASS__, 'map' ) );
	}

	public static function map() {
		if ( ! function_exists( 'vc_map' ) ) {
			return;
		}

		$departments = array( __( 'All departments', 'emotio-team' ) => '' );
		$terms       = get_terms(
			array(
				'taxonomy'   => ETM_CPT::TAX_DEPT,
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$departments[ $term->name ] = $term->slug;
			}
		}

		vc_map(
			array(
				'name'        => __( 'Team Members', 'emotio-team' ),
				'base'        => 'emotio_team',
				'icon'        => 'dashicons dashicons-groups',
				'category'    => __( 'Content', 'js_composer' ),
				'description' => __( 'Grid or slider of team members', 'emotio-team' ),
				'params'      => array(
					array(
						'type'        => 'dropdown',
						'heading'     => __( 'Layout', 'emotio-team' ),
						'param_name'  => 'layout',
						'value'       => array(
							__( 'Grid', 'emotio-team' )   => 'grid',
							__( 'Slider', 'emotio-team' ) => 'slider',
							__( 'List', 'emotio-team' )   => 'list',
						),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Columns', 'emotio-team' ),
						'param_name' => 'columns',
						'value'      => array( '3' => '3', '2' => '2', '4' => '4', '5' => '5', '6' => '6', '1' => '1' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Card style', 'emotio-team' ),
						'param_name' => 'style',
						'value'      => array(
							__( 'Cards', 'emotio-team' )           => 'cards',
							__( 'Minimal', 'emotio-team' )         => 'minimal',
							__( 'Image overlay', 'emotio-team' )   => 'overlay',
							__( 'Circle portrait', 'emotio-team' ) => 'circle',
						),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Hover effect', 'emotio-team' ),
						'param_name' => 'hover',
						'value'      => array(
							__( 'Lift', 'emotio-team' )                => 'lift',
							__( 'Image zoom', 'emotio-team' )          => 'zoom',
							__( 'Swap to hover photo', 'emotio-team' ) => 'swap',
							__( 'Grayscale to colour', 'emotio-team' ) => 'grayscale',
							__( 'None', 'emotio-team' )                => 'none',
						),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Photo ratio', 'emotio-team' ),
						'param_name' => 'image_ratio',
						'value'      => array( '3:4' => '3-4', '1:1' => '1-1', '2:3' => '2-3', '4:3' => '4-3', '16:9' => '16-9' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Department', 'emotio-team' ),
						'param_name' => 'department',
						'value'      => $departments,
						'description' => __( 'Show only members of one department. Multiple: edit the shortcode and comma-separate slugs.', 'emotio-team' ),
					),
					array(
						'type'       => 'textfield',
						'heading'    => __( 'Limit', 'emotio-team' ),
						'param_name' => 'limit',
						'value'      => '-1',
						'description' => __( '-1 shows everyone.', 'emotio-team' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Order by', 'emotio-team' ),
						'param_name' => 'orderby',
						'value'      => array(
							__( 'Custom order', 'emotio-team' ) => 'menu_order',
							__( 'Name', 'emotio-team' )         => 'title',
							__( 'Newest first', 'emotio-team' ) => 'date',
							__( 'Random', 'emotio-team' )       => 'rand',
						),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Card click', 'emotio-team' ),
						'param_name' => 'link',
						'value'      => array(
							__( 'Open profile modal', 'emotio-team' ) => 'modal',
							__( 'Go to profile page', 'emotio-team' ) => 'page',
							__( 'Not clickable', 'emotio-team' )      => 'none',
						),
					),
					array(
						'type'       => 'checkbox',
						'heading'    => __( 'Toolbar', 'emotio-team' ),
						'param_name' => 'show_filter',
						'value'      => array( __( 'Show department filter chips', 'emotio-team' ) => 'yes' ),
					),
					array(
						'type'       => 'checkbox',
						'heading'    => '',
						'param_name' => 'show_search',
						'value'      => array( __( 'Show live search box', 'emotio-team' ) => 'yes' ),
					),
					array(
						'type'       => 'checkbox',
						'heading'    => '',
						'param_name' => 'show_bio',
						'value'      => array( __( 'Show short bio on cards', 'emotio-team' ) => 'yes' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Social icons', 'emotio-team' ),
						'param_name' => 'show_social',
						'value'      => array(
							__( 'Show on card', 'emotio-team' )     => 'yes',
							__( 'Reveal on hover', 'emotio-team' )  => 'hover',
							__( 'Hide', 'emotio-team' )             => 'no',
						),
					),
					array(
						'type'       => 'colorpicker',
						'heading'    => __( 'Accent colour override', 'emotio-team' ),
						'param_name' => 'accent',
						'group'      => __( 'Design', 'emotio-team' ),
					),
					array(
						'type'       => 'textfield',
						'heading'    => __( 'Gap (px)', 'emotio-team' ),
						'param_name' => 'gap',
						'group'      => __( 'Design', 'emotio-team' ),
					),
					array(
						'type'       => 'checkbox',
						'heading'    => __( 'Slider autoplay', 'emotio-team' ),
						'param_name' => 'autoplay',
						'value'      => array( __( 'Autoplay when layout is Slider', 'emotio-team' ) => 'yes' ),
						'group'      => __( 'Design', 'emotio-team' ),
					),
					array(
						'type'       => 'textfield',
						'heading'    => __( 'Autoplay speed (ms)', 'emotio-team' ),
						'param_name' => 'autoplay_speed',
						'value'      => '5000',
						'group'      => __( 'Design', 'emotio-team' ),
					),
					array(
						'type'       => 'textfield',
						'heading'    => __( 'Extra class name', 'emotio-team' ),
						'param_name' => 'class',
						'group'      => __( 'Design', 'emotio-team' ),
					),
				),
			)
		);
	}
}
