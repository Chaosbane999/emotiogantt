<?php
/**
 * Plugin Name:       Emotio Team
 * Plugin URI:        https://github.com/chaosbane999/emotiogantt
 * Description:       Best-in-class team member management for Salient-built WordPress sites. Grid and slider layouts, live search and department filtering, WPBakery element, Gutenberg block, profile modals, vCards, Person schema and deep design controls.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Emotio Design Group
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       emotio-team
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ETM_VERSION', '1.0.0' );
define( 'ETM_FILE', __FILE__ );
define( 'ETM_DIR', plugin_dir_path( __FILE__ ) );
define( 'ETM_URL', plugin_dir_url( __FILE__ ) );

require_once ETM_DIR . 'includes/class-etm-settings.php';
require_once ETM_DIR . 'includes/class-etm-license.php';
require_once ETM_DIR . 'includes/class-etm-cpt.php';
require_once ETM_DIR . 'includes/class-etm-meta.php';
require_once ETM_DIR . 'includes/class-etm-admin.php';
require_once ETM_DIR . 'includes/class-etm-shortcode.php';
require_once ETM_DIR . 'includes/class-etm-block.php';
require_once ETM_DIR . 'includes/class-etm-wpbakery.php';
require_once ETM_DIR . 'includes/class-etm-single.php';

/**
 * Boot the plugin.
 */
function etm_init() {
	load_plugin_textdomain( 'emotio-team', false, dirname( plugin_basename( ETM_FILE ) ) . '/languages' );

	ETM_Settings::init();
	ETM_License::init();
	ETM_CPT::init();
	ETM_Meta::init();
	ETM_Admin::init();
	ETM_Shortcode::init();
	ETM_Block::init();
	ETM_WPBakery::init();
	ETM_Single::init();
}
add_action( 'plugins_loaded', 'etm_init' );

/**
 * Flush rewrite rules on (de)activation so /team/ archive URLs work immediately.
 */
function etm_activate() {
	ETM_Settings::init();
	ETM_CPT::register();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'etm_activate' );

function etm_deactivate() {
	if ( class_exists( 'ETM_License' ) ) {
		ETM_License::unschedule();
	}
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'etm_deactivate' );
