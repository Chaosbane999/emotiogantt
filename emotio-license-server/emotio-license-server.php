<?php
/**
 * Plugin Name:       Emotio License Server
 * Plugin URI:        https://github.com/chaosbane999/emotiogantt
 * Description:       Central licensing for Emotio products (Emotio Team, AI Schema Pro, and any future product). Issues and validates license keys over a REST API, tracks per-site activations and seats, and provides a full admin UI for managing customers' licenses.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Emotio Design Group
 * License:           GPL-2.0-or-later
 * Text Domain:       emotio-license-server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ELS_VERSION', '1.0.0' );
define( 'ELS_FILE', __FILE__ );
define( 'ELS_DIR', plugin_dir_path( __FILE__ ) );

require_once ELS_DIR . 'includes/class-els-products.php';
require_once ELS_DIR . 'includes/class-els-cpt.php';
require_once ELS_DIR . 'includes/class-els-api.php';

function els_init() {
	load_plugin_textdomain( 'emotio-license-server', false, dirname( plugin_basename( ELS_FILE ) ) . '/languages' );
	ELS_Products::init();
	ELS_CPT::init();
	ELS_API::init();
}
add_action( 'plugins_loaded', 'els_init' );

/**
 * Generate a license key: EMO-XXXX-XXXX-XXXX-XXXX using an unambiguous
 * alphabet (no 0/O, 1/I/L).
 */
function els_generate_key() {
	$alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
	$groups   = array();
	for ( $g = 0; $g < 4; $g++ ) {
		$chunk = '';
		for ( $i = 0; $i < 4; $i++ ) {
			$chunk .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		$groups[] = $chunk;
	}
	return 'EMO-' . implode( '-', $groups );
}

/**
 * Normalize a site URL for activation matching (scheme/case/trailing
 * slash insensitive, www-insensitive).
 */
function els_normalize_site( $url ) {
	$url  = strtolower( trim( (string) $url ) );
	$url  = preg_replace( '#^https?://#', '', $url );
	$url  = preg_replace( '#^www\.#', '', $url );
	return untrailingslashit( $url );
}
