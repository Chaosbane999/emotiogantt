<?php
/**
 * Gutenberg block — a live server-rendered wrapper around the shortcode
 * engine so the block, shortcode and WPBakery element always match.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Block {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
	}

	public static function register() {
		wp_register_script(
			'etm-block',
			ETM_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			ETM_VERSION,
			true
		);

		register_block_type(
			'emotio-team/team',
			array(
				'editor_script'   => 'etm-block',
				'render_callback' => array( __CLASS__, 'render' ),
				'attributes'      => array(
					'layout'     => array( 'type' => 'string', 'default' => 'grid' ),
					'columns'    => array( 'type' => 'number', 'default' => (int) ETM_Settings::get( 'columns' ) ),
					'style'      => array( 'type' => 'string', 'default' => ETM_Settings::get( 'style' ) ),
					'hover'      => array( 'type' => 'string', 'default' => ETM_Settings::get( 'hover' ) ),
					'imageRatio' => array( 'type' => 'string', 'default' => ETM_Settings::get( 'image_ratio' ) ),
					'link'       => array( 'type' => 'string', 'default' => ETM_Settings::get( 'link' ) ),
					'department' => array( 'type' => 'string', 'default' => '' ),
					'tag'        => array( 'type' => 'string', 'default' => '' ),
					'limit'      => array( 'type' => 'number', 'default' => -1 ),
					'orderby'    => array( 'type' => 'string', 'default' => 'menu_order' ),
					'showFilter' => array( 'type' => 'boolean', 'default' => false ),
					'showSearch' => array( 'type' => 'boolean', 'default' => false ),
					'showSocial' => array( 'type' => 'boolean', 'default' => true ),
					'showBio'    => array( 'type' => 'boolean', 'default' => false ),
					'accent'     => array( 'type' => 'string', 'default' => '' ),
					'sliderStyle' => array( 'type' => 'string', 'default' => 'drag' ),
					'groupBy'     => array( 'type' => 'boolean', 'default' => false ),
					'relation'    => array( 'type' => 'string', 'default' => 'OR' ),
					'columnsTablet' => array( 'type' => 'string', 'default' => '' ),
					'columnsMobile' => array( 'type' => 'string', 'default' => '' ),
					'showEmail'      => array( 'type' => 'boolean', 'default' => false ),
					'showPhone'      => array( 'type' => 'boolean', 'default' => false ),
					'showLocation'   => array( 'type' => 'boolean', 'default' => false ),
					'showDepartment' => array( 'type' => 'boolean', 'default' => false ),
					'spacing'     => array( 'type' => 'string', 'default' => ETM_Settings::get( 'spacing' ) ),
					'nameSize'    => array( 'type' => 'string', 'default' => '' ),
					'nameColor'   => array( 'type' => 'string', 'default' => '' ),
					'titleSize'   => array( 'type' => 'string', 'default' => '' ),
					'titleColor'  => array( 'type' => 'string', 'default' => '' ),
					'bioSize'     => array( 'type' => 'string', 'default' => '' ),
					'bioColor'    => array( 'type' => 'string', 'default' => '' ),
					'socialSize'  => array( 'type' => 'string', 'default' => '' ),
					'socialColor' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}

	public static function render( $attributes ) {
		return ETM_Shortcode::render(
			array(
				'layout'      => $attributes['layout'] ?? 'grid',
				'columns'     => $attributes['columns'] ?? 3,
				'style'       => $attributes['style'] ?? 'cards',
				'hover'       => $attributes['hover'] ?? 'lift',
				'image_ratio' => $attributes['imageRatio'] ?? '3-4',
				'link'        => $attributes['link'] ?? 'modal',
				'department'  => $attributes['department'] ?? '',
				'tag'         => $attributes['tag'] ?? '',
				'limit'       => $attributes['limit'] ?? -1,
				'orderby'     => $attributes['orderby'] ?? 'menu_order',
				'show_filter' => ! empty( $attributes['showFilter'] ) ? 'yes' : 'no',
				'show_search' => ! empty( $attributes['showSearch'] ) ? 'yes' : 'no',
				'show_social' => ! empty( $attributes['showSocial'] ) ? 'yes' : 'no',
				'show_bio'    => ! empty( $attributes['showBio'] ) ? 'yes' : 'no',
				'accent'      => $attributes['accent'] ?? '',
				'slider_style' => $attributes['sliderStyle'] ?? 'drag',
				'group_by'     => ! empty( $attributes['groupBy'] ) ? 'department' : '',
				'relation'     => $attributes['relation'] ?? 'OR',
				'columns_tablet' => $attributes['columnsTablet'] ?? '',
				'columns_mobile' => $attributes['columnsMobile'] ?? '',
				'show_email'      => ! empty( $attributes['showEmail'] ) ? 'yes' : 'no',
				'show_phone'      => ! empty( $attributes['showPhone'] ) ? 'yes' : 'no',
				'show_location'   => ! empty( $attributes['showLocation'] ) ? 'yes' : 'no',
				'show_department' => ! empty( $attributes['showDepartment'] ) ? 'yes' : 'no',
				'spacing'      => $attributes['spacing'] ?? 'normal',
				'name_size'    => $attributes['nameSize'] ?? '',
				'name_color'   => $attributes['nameColor'] ?? '',
				'title_size'   => $attributes['titleSize'] ?? '',
				'title_color'  => $attributes['titleColor'] ?? '',
				'bio_size'     => $attributes['bioSize'] ?? '',
				'bio_color'    => $attributes['bioColor'] ?? '',
				'social_size'  => $attributes['socialSize'] ?? '',
				'social_color' => $attributes['socialColor'] ?? '',
			)
		);
	}
}
