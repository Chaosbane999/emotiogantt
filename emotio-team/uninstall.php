<?php
/**
 * Uninstall — removes plugin options. Team member content is deliberately
 * left intact so uninstalling never destroys people's profiles; delete the
 * Team posts manually if you want them gone.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'etm_settings' );
delete_option( 'etm_flush_needed' );
