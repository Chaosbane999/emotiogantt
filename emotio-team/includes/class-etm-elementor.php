<?php
/**
 * Elementor integration — registers the Team Members widget when
 * Elementor (3.5+) is active. Salient sites use WPBakery, but this makes
 * the plugin first-class on Elementor builds too.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Elementor {

	public static function init() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widget' ) );
	}

	public static function register_widget( $widgets_manager ) {
		require_once ETM_DIR . 'includes/class-etm-elementor-widget.php';
		if ( method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( new ETM_Elementor_Widget() );
		} elseif ( method_exists( $widgets_manager, 'register_widget_type' ) ) {
			$widgets_manager->register_widget_type( new ETM_Elementor_Widget() );
		}
	}
}
