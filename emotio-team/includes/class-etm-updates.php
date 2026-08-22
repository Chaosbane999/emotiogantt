<?php
/**
 * Self-hosted automatic updates, gated on an active Emotio license.
 *
 * The update source is a small JSON manifest hosted on
 * emotio-design-group.co.uk (no server-side code needed — upload the
 * manifest and the new plugin zip whenever you release):
 *
 *   https://emotio-design-group.co.uk/updates/emotio-team.json
 *   {
 *     "version":      "1.4.0",
 *     "package":      "https://emotio-design-group.co.uk/updates/emotio-team-1.4.0.zip",
 *     "requires":     "5.8",
 *     "requires_php": "7.4",
 *     "tested":       "6.7",
 *     "changelog":    "<h4>1.4.0</h4><ul><li>...</li></ul>"
 *   }
 *
 * Override the manifest URL with the ETM_UPDATE_MANIFEST constant or the
 * etm_update_manifest_url filter. Sites without an active license (or
 * trial) are never offered the package — updates are a license benefit.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Updates {

	const CACHE_KEY = 'etm_update_manifest';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_cache' ), 10, 2 );
	}

	public static function manifest_url() {
		$url = defined( 'ETM_UPDATE_MANIFEST' ) ? ETM_UPDATE_MANIFEST : 'https://emotio-design-group.co.uk/updates/emotio-team.json';
		return apply_filters( 'etm_update_manifest_url', $url );
	}

	/**
	 * Fetch (and cache) the release manifest.
	 *
	 * @return array|null Validated manifest, or null.
	 */
	public static function manifest() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$response = wp_remote_get( self::manifest_url(), array( 'timeout' => 10 ) );
		$manifest = array();

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['version'] ) && ! empty( $body['package'] )
				&& preg_match( '/^\d+(\.\d+)*$/', (string) $body['version'] )
				&& 0 === strpos( (string) $body['package'], 'https://' ) ) {
				$manifest = array(
					'version'      => (string) $body['version'],
					'package'      => esc_url_raw( $body['package'] ),
					'requires'     => sanitize_text_field( $body['requires'] ?? '5.8' ),
					'requires_php' => sanitize_text_field( $body['requires_php'] ?? '7.4' ),
					'tested'       => sanitize_text_field( $body['tested'] ?? '' ),
					'changelog'    => wp_kses_post( $body['changelog'] ?? '' ),
				);
			}
		}

		// Cache failures too (as an empty array) so a down server is only
		// re-tried every 12 hours.
		set_site_transient( self::CACHE_KEY, $manifest, self::CACHE_TTL );
		return empty( $manifest['version'] ) ? null : $manifest;
	}

	/**
	 * Offer the update to WordPress when a newer, licensed release exists.
	 */
	public static function inject_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}
		$manifest = self::manifest();
		if ( ! $manifest || ! version_compare( $manifest['version'], ETM_VERSION, '>' ) ) {
			return $transient;
		}
		if ( ! ETM_License::is_licensed() ) {
			return $transient; // Updates are a license benefit.
		}

		$plugin = plugin_basename( ETM_FILE );

		$transient->response[ $plugin ] = (object) array(
			'slug'         => 'emotio-team',
			'plugin'       => $plugin,
			'new_version'  => $manifest['version'],
			'package'      => $manifest['package'],
			'url'          => 'https://emotio-design-group.co.uk',
			'tested'       => $manifest['tested'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
		);

		return $transient;
	}

	/**
	 * Populate the "View details" modal.
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'emotio-team' !== $args->slug ) {
			return $result;
		}
		$manifest = self::manifest();
		if ( ! $manifest ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Emotio Team Pro',
			'slug'          => 'emotio-team',
			'version'       => $manifest['version'],
			'author'        => '<a href="https://emotio-design-group.co.uk">Emotio Design Group</a>',
			'homepage'      => 'https://emotio-design-group.co.uk',
			'requires'      => $manifest['requires'],
			'requires_php'  => $manifest['requires_php'],
			'tested'        => $manifest['tested'],
			'download_link' => ETM_License::is_licensed() ? $manifest['package'] : '',
			'sections'      => array(
				'description' => __( 'Best-in-class team member management for Salient-built WordPress sites.', 'emotio-team' ),
				'changelog'   => $manifest['changelog'] ?: __( 'See emotio-design-group.co.uk for release notes.', 'emotio-team' ),
			),
		);
	}

	public static function flush_cache( $upgrader, $hook_extra ) {
		if ( isset( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'] ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}
}
