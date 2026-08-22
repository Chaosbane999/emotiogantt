<?php
/**
 * Elementor widget: Team Members. A thin control surface over the same
 * render engine the shortcode, Gutenberg block and WPBakery element use.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'emotio-team';
	}

	public function get_title() {
		return __( 'Team Members', 'emotio-team' );
	}

	public function get_icon() {
		return 'eicon-person';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'team', 'staff', 'people', 'members', 'emotio' );
	}

	protected function register_controls() {
		$cm = \Elementor\Controls_Manager::class;

		/* ------------------------------------------------ who to show */
		$this->start_controls_section( 'etm_content', array( 'label' => __( 'Who to show', 'emotio-team' ) ) );
		$this->add_control( 'department', array(
			'label'       => __( 'Department slugs', 'emotio-team' ),
			'type'        => $cm::TEXT,
			'description' => __( 'Comma-separate for multiple; empty shows everyone.', 'emotio-team' ),
		) );
		$this->add_control( 'tag', array(
			'label' => __( 'Skill / tag slugs', 'emotio-team' ),
			'type'  => $cm::TEXT,
		) );
		$this->add_control( 'featured', array(
			'label'        => __( 'Featured members only', 'emotio-team' ),
			'type'         => $cm::SWITCHER,
			'return_value' => 'yes',
		) );
		$this->add_control( 'limit', array(
			'label'   => __( 'Limit (-1 = all)', 'emotio-team' ),
			'type'    => $cm::NUMBER,
			'default' => -1,
			'min'     => -1,
		) );
		$this->add_control( 'orderby', array(
			'label'   => __( 'Order by', 'emotio-team' ),
			'type'    => $cm::SELECT,
			'default' => 'menu_order',
			'options' => array(
				'menu_order' => __( 'Custom order', 'emotio-team' ),
				'title'      => __( 'Name', 'emotio-team' ),
				'date'       => __( 'Newest first', 'emotio-team' ),
				'rand'       => __( 'Random', 'emotio-team' ),
			),
		) );
		$this->end_controls_section();

		/* ----------------------------------------------------- layout */
		$this->start_controls_section( 'etm_layout', array( 'label' => __( 'Layout', 'emotio-team' ) ) );
		$this->add_control( 'layout', array(
			'label'   => __( 'Layout', 'emotio-team' ),
			'type'    => $cm::SELECT,
			'default' => 'grid',
			'options' => array(
				'grid'      => __( 'Grid', 'emotio-team' ),
				'slider'    => __( 'Slider', 'emotio-team' ),
				'list'      => __( 'List', 'emotio-team' ),
				'spotlight' => __( 'Spotlight (featured large + grid)', 'emotio-team' ),
			),
		) );
		$this->add_control( 'slider_style', array(
			'label'     => __( 'Slider style', 'emotio-team' ),
			'type'      => $cm::SELECT,
			'default'   => 'drag',
			'options'   => array(
				'drag'  => __( 'Drag / swipe with momentum', 'emotio-team' ),
				'paged' => __( 'Paged with arrows & dots', 'emotio-team' ),
			),
			'condition' => array( 'layout' => 'slider' ),
		) );
		$this->add_control( 'group_by_department', array(
			'label'        => __( 'Group by department', 'emotio-team' ),
			'type'         => $cm::SWITCHER,
			'return_value' => 'yes',
			'condition'    => array( 'layout' => array( 'grid', 'list' ) ),
		) );
		$this->add_control( 'columns', array(
			'label'   => __( 'Columns', 'emotio-team' ),
			'type'    => $cm::SELECT,
			'default' => '3',
			'options' => array( '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6' ),
		) );
		$this->add_control( 'link', array(
			'label'   => __( 'Card click', 'emotio-team' ),
			'type'    => $cm::SELECT,
			'default' => 'modal',
			'options' => array(
				'modal' => __( 'Open profile modal', 'emotio-team' ),
				'panel' => __( 'Slide-out profile panel', 'emotio-team' ),
				'page'  => __( 'Go to profile page', 'emotio-team' ),
				'none'  => __( 'Not clickable', 'emotio-team' ),
			),
		) );
		$this->add_control( 'show_filter', array( 'label' => __( 'Department filter chips', 'emotio-team' ), 'type' => $cm::SWITCHER, 'return_value' => 'yes' ) );
		$this->add_control( 'show_search', array( 'label' => __( 'Live search box', 'emotio-team' ), 'type' => $cm::SWITCHER, 'return_value' => 'yes' ) );
		$this->add_control( 'show_bio', array( 'label' => __( 'Short bio on cards', 'emotio-team' ), 'type' => $cm::SWITCHER, 'return_value' => 'yes' ) );
		$this->add_control( 'show_social', array(
			'label'   => __( 'Social icons', 'emotio-team' ),
			'type'    => $cm::SELECT,
			'default' => 'yes',
			'options' => array(
				'yes'   => __( 'Show on card', 'emotio-team' ),
				'hover' => __( 'Reveal on hover', 'emotio-team' ),
				'no'    => __( 'Hide', 'emotio-team' ),
			),
		) );
		$this->add_control( 'autoplay', array(
			'label'        => __( 'Slider autoplay', 'emotio-team' ),
			'type'         => $cm::SWITCHER,
			'return_value' => 'yes',
			'condition'    => array( 'layout' => 'slider' ),
		) );
		$this->end_controls_section();

		/* ----------------------------------------------------- design */
		$this->start_controls_section( 'etm_design', array( 'label' => __( 'Design', 'emotio-team' ) ) );
		$this->add_control( 'style', array(
			'label'   => __( 'Card style', 'emotio-team' ),
			'type'    => $cm::SELECT,
			'default' => 'cards',
			'options' => array(
				'cards'   => __( 'Cards', 'emotio-team' ),
				'minimal' => __( 'Minimal', 'emotio-team' ),
				'overlay' => __( 'Image overlay', 'emotio-team' ),
				'circle'  => __( 'Circle portrait', 'emotio-team' ),
			),
		) );
		$this->add_control( 'hover', array(
			'label'   => __( 'Hover effect', 'emotio-team' ),
			'type'    => $cm::SELECT,
			'default' => 'lift',
			'options' => array(
				'lift'      => __( 'Lift', 'emotio-team' ),
				'zoom'      => __( 'Image zoom', 'emotio-team' ),
				'swap'      => __( 'Swap to hover photo', 'emotio-team' ),
				'grayscale' => __( 'Grayscale to colour', 'emotio-team' ),
				'none'      => __( 'None', 'emotio-team' ),
			),
		) );
		$this->add_control( 'image_ratio', array(
			'label'   => __( 'Photo ratio', 'emotio-team' ),
			'type'    => $cm::SELECT,
			'default' => '3-4',
			'options' => array( '3-4' => '3:4', '1-1' => '1:1', '2-3' => '2:3', '4-3' => '4:3', '16-9' => '16:9' ),
		) );
		$this->add_control( 'spacing', array(
			'label'   => __( 'Element spacing', 'emotio-team' ),
			'type'    => $cm::SELECT,
			'default' => 'normal',
			'options' => array(
				'tight'  => __( 'Tight', 'emotio-team' ),
				'normal' => __( 'Normal', 'emotio-team' ),
				'spaced' => __( 'Spaced', 'emotio-team' ),
			),
		) );
		$this->add_control( 'accent', array( 'label' => __( 'Accent colour', 'emotio-team' ), 'type' => $cm::COLOR ) );
		$this->add_control( 'gap', array( 'label' => __( 'Grid gap (px)', 'emotio-team' ), 'type' => $cm::NUMBER, 'min' => 0, 'max' => 80 ) );
		$this->end_controls_section();

		/* ------------------------------------------------- typography */
		$this->start_controls_section( 'etm_typography', array( 'label' => __( 'Typography & colours', 'emotio-team' ) ) );
		foreach ( array(
			'name'   => __( 'Name', 'emotio-team' ),
			'title'  => __( 'Job title', 'emotio-team' ),
			'bio'    => __( 'Snippet', 'emotio-team' ),
			'social' => __( 'Social icons', 'emotio-team' ),
		) as $el => $label ) {
			$this->add_control( $el . '_size', array(
				/* translators: %s: element label */
				'label' => sprintf( __( '%s size (px)', 'emotio-team' ), $label ),
				'type'  => $cm::NUMBER,
				'min'   => 0,
				'max'   => 80,
			) );
			$this->add_control( $el . '_color', array(
				/* translators: %s: element label */
				'label' => sprintf( __( '%s colour', 'emotio-team' ), $label ),
				'type'  => $cm::COLOR,
			) );
		}
		$this->end_controls_section();
	}

	protected function render() {
		$s = $this->get_settings_for_display();

		echo ETM_Shortcode::render( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'layout'       => $s['layout'] ?? 'grid',
				'slider_style' => $s['slider_style'] ?? 'drag',
				'group_by'     => ( $s['group_by_department'] ?? '' ) === 'yes' ? 'department' : '',
				'columns'      => $s['columns'] ?? 3,
				'style'        => $s['style'] ?? 'cards',
				'hover'        => $s['hover'] ?? 'lift',
				'image_ratio'  => $s['image_ratio'] ?? '3-4',
				'link'         => $s['link'] ?? 'modal',
				'department'   => $s['department'] ?? '',
				'tag'          => $s['tag'] ?? '',
				'featured'     => ( $s['featured'] ?? '' ) === 'yes' ? 'yes' : '',
				'limit'        => $s['limit'] ?? -1,
				'orderby'      => $s['orderby'] ?? 'menu_order',
				'show_filter'  => ( $s['show_filter'] ?? '' ) === 'yes' ? 'yes' : 'no',
				'show_search'  => ( $s['show_search'] ?? '' ) === 'yes' ? 'yes' : 'no',
				'show_bio'     => ( $s['show_bio'] ?? '' ) === 'yes' ? 'yes' : 'no',
				'show_social'  => $s['show_social'] ?? 'yes',
				'autoplay'     => ( $s['autoplay'] ?? '' ) === 'yes' ? 'yes' : 'no',
				'spacing'      => $s['spacing'] ?? 'normal',
				'accent'       => $s['accent'] ?? '',
				'gap'          => '' !== ( $s['gap'] ?? '' ) && null !== ( $s['gap'] ?? null ) ? $s['gap'] : '',
				'name_size'    => $s['name_size'] ?? '',
				'name_color'   => $s['name_color'] ?? '',
				'title_size'   => $s['title_size'] ?? '',
				'title_color'  => $s['title_color'] ?? '',
				'bio_size'     => $s['bio_size'] ?? '',
				'bio_color'    => $s['bio_color'] ?? '',
				'social_size'  => $s['social_size'] ?? '',
				'social_color' => $s['social_color'] ?? '',
			)
		);
	}
}
