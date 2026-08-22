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
				'category'    => 'Emotio',
				'description' => __( 'Grid or slider of team members', 'emotio-team' ),
				'params'      => array(
					array(
						'type'        => 'dropdown',
						'heading'     => __( 'Layout', 'emotio-team' ),
						'param_name'  => 'layout',
						'value'       => array(
							__( 'Grid', 'emotio-team' )                              => 'grid',
							__( 'Slider', 'emotio-team' )                            => 'slider',
							__( 'List', 'emotio-team' )                              => 'list',
							__( 'Spotlight (featured large + grid)', 'emotio-team' ) => 'spotlight',
						),
					),
					array(
						'type'       => 'checkbox',
						'heading'    => __( 'Grouping', 'emotio-team' ),
						'param_name' => 'group_by',
						'value'      => array( __( 'Group members under department headings', 'emotio-team' ) => 'department' ),
						'dependency' => array( 'element' => 'layout', 'value' => array( 'grid', 'list' ) ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Slider style', 'emotio-team' ),
						'param_name' => 'slider_style',
						'value'      => array(
							__( 'Drag / swipe with momentum (Area Pro style)', 'emotio-team' ) => 'drag',
							__( 'Paged with arrows & dots', 'emotio-team' )                    => 'paged',
						),
						'dependency' => array( 'element' => 'layout', 'value' => 'slider' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Columns', 'emotio-team' ),
						'param_name' => 'columns',
						'value'      => array( '3' => '3', '2' => '2', '4' => '4', '5' => '5', '6' => '6', '1' => '1' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Columns — tablet', 'emotio-team' ),
						'param_name' => 'columns_tablet',
						'value'      => array( __( 'Auto', 'emotio-team' ) => '', '1' => '1', '2' => '2', '3' => '3', '4' => '4' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Columns — mobile', 'emotio-team' ),
						'param_name' => 'columns_mobile',
						'value'      => array( __( 'Auto', 'emotio-team' ) => '', '1' => '1', '2' => '2', '3' => '3' ),
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
						'type'       => 'dropdown',
						'heading'    => __( 'Multiple departments match', 'emotio-team' ),
						'param_name' => 'relation',
						'value'      => array(
							__( 'ANY of them (OR)', 'emotio-team' ) => 'OR',
							__( 'ALL of them (AND)', 'emotio-team' ) => 'AND',
						),
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
							__( 'Job title', 'emotio-team' )    => 'job_title',
							__( 'Newest first', 'emotio-team' ) => 'date',
							__( 'ID', 'emotio-team' )           => 'id',
							__( 'Random', 'emotio-team' )       => 'rand',
						),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Card click', 'emotio-team' ),
						'param_name' => 'link',
						'value'      => array(
							__( 'Open profile modal', 'emotio-team' )       => 'modal',
							__( 'Slide-out profile panel', 'emotio-team' )  => 'panel',
							__( 'Go to profile page', 'emotio-team' )       => 'page',
							__( 'Custom URL (per member)', 'emotio-team' )  => 'custom',
							__( 'Not clickable', 'emotio-team' )            => 'none',
						),
					),
					array(
						'type'       => 'checkbox',
						'heading'    => __( 'Card fields', 'emotio-team' ),
						'param_name' => 'show_department',
						'value'      => array( __( 'Show department', 'emotio-team' ) => 'yes' ),
					),
					array(
						'type'       => 'checkbox',
						'heading'    => '',
						'param_name' => 'show_email',
						'value'      => array( __( 'Show email', 'emotio-team' ) => 'yes' ),
					),
					array(
						'type'       => 'checkbox',
						'heading'    => '',
						'param_name' => 'show_phone',
						'value'      => array( __( 'Show phone / mobile', 'emotio-team' ) => 'yes' ),
					),
					array(
						'type'       => 'checkbox',
						'heading'    => '',
						'param_name' => 'show_location',
						'value'      => array( __( 'Show location', 'emotio-team' ) => 'yes' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Element spacing', 'emotio-team' ),
						'param_name' => 'spacing',
						'value'      => array(
							__( 'Normal', 'emotio-team' ) => 'normal',
							__( 'Tight', 'emotio-team' )  => 'tight',
							__( 'Spaced', 'emotio-team' ) => 'spaced',
						),
						'description' => __( 'Gap between name, title, snippet and socials. Edit the shortcode for an exact pixel value.', 'emotio-team' ),
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
					array( 'type' => 'textfield', 'heading' => __( 'Name font size (px)', 'emotio-team' ), 'param_name' => 'name_size', 'group' => __( 'Typography', 'emotio-team' ), 'description' => __( 'Empty = inherit from theme.', 'emotio-team' ) ),
					array( 'type' => 'colorpicker', 'heading' => __( 'Name colour', 'emotio-team' ), 'param_name' => 'name_color', 'group' => __( 'Typography', 'emotio-team' ) ),
					array( 'type' => 'textfield', 'heading' => __( 'Job title font size (px)', 'emotio-team' ), 'param_name' => 'title_size', 'group' => __( 'Typography', 'emotio-team' ) ),
					array( 'type' => 'colorpicker', 'heading' => __( 'Job title colour', 'emotio-team' ), 'param_name' => 'title_color', 'group' => __( 'Typography', 'emotio-team' ) ),
					array( 'type' => 'textfield', 'heading' => __( 'Snippet font size (px)', 'emotio-team' ), 'param_name' => 'bio_size', 'group' => __( 'Typography', 'emotio-team' ) ),
					array( 'type' => 'colorpicker', 'heading' => __( 'Snippet colour', 'emotio-team' ), 'param_name' => 'bio_color', 'group' => __( 'Typography', 'emotio-team' ) ),
					array( 'type' => 'textfield', 'heading' => __( 'Social icon size (px)', 'emotio-team' ), 'param_name' => 'social_size', 'group' => __( 'Typography', 'emotio-team' ) ),
					array( 'type' => 'colorpicker', 'heading' => __( 'Social icon colour', 'emotio-team' ), 'param_name' => 'social_color', 'group' => __( 'Typography', 'emotio-team' ) ),
				),
			)
		);

		$members = class_exists( 'ETM_Triggers' ) ? ETM_Triggers::members_dropdown() : array();

		vc_map(
			array(
				'name'        => __( 'Team Member Card', 'emotio-team' ),
				'base'        => 'emotio_team_member',
				'icon'        => 'dashicons dashicons-id-alt',
				'category'    => 'Emotio',
				'description' => __( 'One member\'s card, anywhere on a page', 'emotio-team' ),
				'params'      => array(
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Team member', 'emotio-team' ),
						'param_name' => 'id',
						'value'      => $members,
						'admin_label' => true,
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
						'heading'    => __( 'Card click', 'emotio-team' ),
						'param_name' => 'link',
						'value'      => array(
							__( 'Open profile modal', 'emotio-team' )      => 'modal',
							__( 'Slide-out profile panel', 'emotio-team' ) => 'panel',
							__( 'Go to profile page', 'emotio-team' )      => 'page',
							__( 'Not clickable', 'emotio-team' )           => 'none',
						),
					),
					array(
						'type'       => 'checkbox',
						'heading'    => '',
						'param_name' => 'show_bio',
						'value'      => array( __( 'Show short bio', 'emotio-team' ) => 'yes' ),
						'std'        => 'yes',
					),
					array(
						'type'       => 'colorpicker',
						'heading'    => __( 'Accent colour override', 'emotio-team' ),
						'param_name' => 'accent',
					),
				),
			)
		);

		$saved = array( __( '— Select a saved display —', 'emotio-team' ) => '' );
		if ( class_exists( 'ETM_Generator' ) ) {
			foreach ( ETM_Generator::displays()['items'] as $display_id => $display ) {
				$saved[ $display['name'] . ' (#' . $display_id . ')' ] = (string) $display_id;
			}
		}

		vc_map(
			array(
				'name'        => __( 'Saved Team Display', 'emotio-team' ),
				'base'        => 'emotio_team_display',
				'icon'        => 'dashicons dashicons-star-filled',
				'category'    => 'Emotio',
				'description' => __( 'A reusable display from the Shortcode Generator', 'emotio-team' ),
				'params'      => array(
					array(
						'type'        => 'dropdown',
						'heading'     => __( 'Saved display', 'emotio-team' ),
						'param_name'  => 'id',
						'value'       => $saved,
						'admin_label' => true,
						'description' => __( 'Create and manage saved displays under Team → Shortcode Generator.', 'emotio-team' ),
					),
				),
			)
		);

		vc_map(
			array(
				'name'        => __( 'Team Search Bar', 'emotio-team' ),
				'base'        => 'emotio_team_search',
				'icon'        => 'dashicons dashicons-search',
				'category'    => 'Emotio',
				'description' => __( 'Live search box that filters a team layout on the page', 'emotio-team' ),
				'params'      => array(
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Placeholder', 'emotio-team' ),
						'param_name'  => 'placeholder',
					),
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Target (CSS selector)', 'emotio-team' ),
						'param_name'  => 'target',
						'description' => __( 'Leave empty to control the first team layout on the page.', 'emotio-team' ),
					),
				),
			)
		);

		vc_map(
			array(
				'name'        => __( 'Team Department Filter', 'emotio-team' ),
				'base'        => 'emotio_team_filter',
				'icon'        => 'dashicons dashicons-filter',
				'category'    => 'Emotio',
				'description' => __( 'Department chips that filter a team layout on the page', 'emotio-team' ),
				'params'      => array(
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Departments (slugs, comma-separated)', 'emotio-team' ),
						'param_name'  => 'departments',
						'description' => __( 'Empty shows every department in use.', 'emotio-team' ),
					),
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Target (CSS selector)', 'emotio-team' ),
						'param_name'  => 'target',
						'description' => __( 'Leave empty to control the first team layout on the page.', 'emotio-team' ),
					),
				),
			)
		);
	}
}
