<?php
/**
 * Single profile pages, the team archive, vCard downloads and Person schema.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Single {

	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'templates' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_vcard' ) );
		add_action( 'wp_head', array( __CLASS__, 'single_schema' ) );
	}

	/**
	 * Use the plugin's templates unless the theme provides its own
	 * (drop overrides into {theme}/emotio-team/).
	 */
	public static function templates( $template ) {
		if ( is_singular( ETM_CPT::POST_TYPE ) ) {
			return self::locate( 'single-team_member.php', $template );
		}
		if ( is_post_type_archive( ETM_CPT::POST_TYPE ) || is_tax( array( ETM_CPT::TAX_DEPT, ETM_CPT::TAX_TAG ) ) ) {
			return self::locate( 'archive-team_member.php', $template );
		}
		return $template;
	}

	protected static function locate( $file, $fallback ) {
		$theme = locate_template( array( 'emotio-team/' . $file, $file ) );
		if ( $theme ) {
			return $theme;
		}
		$plugin = ETM_DIR . 'templates/' . $file;
		return file_exists( $plugin ) ? $plugin : $fallback;
	}

	public static function query_vars( $vars ) {
		$vars[] = 'etm_vcard';
		return $vars;
	}

	public static function vcard_url( $post_id ) {
		return add_query_arg( 'etm_vcard', absint( $post_id ), home_url( '/' ) );
	}

	/**
	 * Stream a .vcf contact card for a member.
	 */
	public static function maybe_vcard() {
		$id = absint( get_query_var( 'etm_vcard' ) );
		if ( ! $id ) {
			return;
		}

		$post = get_post( $id );
		if ( ! $post || ETM_CPT::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			wp_die( esc_html__( 'Team member not found.', 'emotio-team' ), 404 );
		}

		$name  = get_the_title( $post );
		$parts = explode( ' ', $name, 2 );
		$first = $parts[0];
		$last  = isset( $parts[1] ) ? $parts[1] : '';

		$lines = array(
			'BEGIN:VCARD',
			'VERSION:3.0',
			'N:' . self::vesc( $last ) . ';' . self::vesc( $first ) . ';;;',
			'FN:' . self::vesc( $name ),
			'ORG:' . self::vesc( get_bloginfo( 'name' ) ),
		);

		$title = ETM_Meta::get( $id, 'job_title' );
		if ( $title ) {
			$lines[] = 'TITLE:' . self::vesc( $title );
		}
		$email = ETM_Meta::get( $id, 'email' );
		if ( $email ) {
			$lines[] = 'EMAIL;TYPE=WORK:' . self::vesc( $email );
		}
		$phone = ETM_Meta::get( $id, 'phone' );
		if ( $phone ) {
			$lines[] = 'TEL;TYPE=WORK,VOICE:' . self::vesc( $phone );
		}
		if ( ETM_Settings::get( 'enable_single' ) ) {
			$lines[] = 'URL:' . esc_url_raw( get_permalink( $post ) );
		}
		$socials = ETM_Meta::socials( $id );
		foreach ( $socials as $key => $social ) {
			$lines[] = 'URL;TYPE=' . strtoupper( $key ) . ':' . esc_url_raw( $social['url'] );
		}
		$lines[] = 'REV:' . gmdate( 'Ymd\THis\Z' );
		$lines[] = 'END:VCARD';

		nocache_headers();
		header( 'Content-Type: text/vcard; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '.vcf"' );
		echo implode( "\r\n", $lines ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	protected static function vesc( $value ) {
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), $value );
	}

	/**
	 * Person JSON-LD on single profiles.
	 */
	public static function single_schema() {
		if ( ! is_singular( ETM_CPT::POST_TYPE ) || ! ETM_Settings::get( 'enable_schema' ) ) {
			return;
		}
		$id      = get_queried_object_id();
		$socials = ETM_Meta::socials( $id );
		$schema  = array_filter(
			array(
				'@context' => 'https://schema.org',
				'@type'    => 'Person',
				'name'     => get_the_title( $id ),
				'jobTitle' => ETM_Meta::get( $id, 'job_title' ),
				'email'    => ETM_Meta::get( $id, 'email' ) ? 'mailto:' . ETM_Meta::get( $id, 'email' ) : '',
				'url'      => get_permalink( $id ),
				'image'    => get_the_post_thumbnail_url( $id, 'large' ),
				'sameAs'   => array_values( wp_list_pluck( $socials, 'url' ) ),
				'worksFor' => array(
					'@type' => 'Organization',
					'name'  => get_bloginfo( 'name' ),
					'url'   => home_url( '/' ),
				),
			)
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
