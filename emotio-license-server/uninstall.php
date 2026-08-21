<?php
/**
 * Uninstall — removes the product registry option only. License records
 * (the emotio_license posts) are deliberately kept: they are your
 * customers' licenses. Delete them from the Licenses screen if you truly
 * want them gone.
 *
 * @package Emotio_License_Server
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'els_products' );
