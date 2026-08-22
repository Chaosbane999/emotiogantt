<?php
/**
 * Plugin settings: design tokens, defaults, behaviour toggles.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Settings {

	const OPTION = 'etm_settings';

	/** @var array|null Cached settings. */
	protected static $settings = null;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Default settings.
	 */
	public static function defaults() {
		return array(
			'accent'         => '#1f2937',
			'card_bg'        => '#ffffff',
			'text_color'     => '',
			'radius'         => 10,
			'gap'            => 28,
			'columns'        => 3,
			'style'          => 'cards',
			'hover'          => 'lift',
			'image_ratio'    => '3-4',
			'link'           => 'modal',
			'spacing'        => 'normal',
			'name_size'      => 0,
			'name_color'     => '',
			'title_size'     => 0,
			'title_color'    => '',
			'bio_size'       => 0,
			'bio_color'      => '',
			'social_size'    => 18,
			'social_color'   => '',
			'custom_fields'  => array(),
			'archive_slug'   => 'team',
			'enable_single'  => 1,
			'enable_archive' => 1,
			'enable_schema'  => 1,
			'global_triggers' => 1,
			'custom_css'     => '',
		);
	}

	/**
	 * Get a single setting (or all).
	 */
	public static function get( $key = null ) {
		if ( null === self::$settings ) {
			self::$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		}
		if ( null === $key ) {
			return self::$settings;
		}
		return isset( self::$settings[ $key ] ) ? self::$settings[ $key ] : null;
	}

	public static function add_page() {
		add_submenu_page(
			'edit.php?post_type=team_member',
			__( 'Team Settings', 'emotio-team' ),
			__( 'Settings', 'emotio-team' ),
			'manage_options',
			'etm-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting(
			'etm_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/**
	 * Sanitize the settings array.
	 */
	public static function sanitize( $input ) {
		$out = self::defaults();
		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['accent']     = sanitize_hex_color( $input['accent'] ?? '' ) ?: $out['accent'];
		$out['card_bg']    = sanitize_hex_color( $input['card_bg'] ?? '' ) ?: $out['card_bg'];
		$out['text_color'] = sanitize_hex_color( $input['text_color'] ?? '' ) ?: '';
		$out['radius']     = max( 0, min( 60, absint( $input['radius'] ?? 10 ) ) );
		$out['gap']        = max( 0, min( 80, absint( $input['gap'] ?? 28 ) ) );
		$out['columns']    = max( 1, min( 6, absint( $input['columns'] ?? 3 ) ) );

		$styles  = array( 'cards', 'minimal', 'overlay', 'circle' );
		$hovers  = array( 'lift', 'zoom', 'swap', 'grayscale', 'none' );
		$ratios  = array( '1-1', '3-4', '2-3', '4-3', '16-9' );
		$links   = array( 'modal', 'panel', 'page', 'none' );
		$spaces  = array( 'tight', 'normal', 'spaced' );

		$out['style']       = in_array( $input['style'] ?? '', $styles, true ) ? $input['style'] : $out['style'];
		$out['hover']       = in_array( $input['hover'] ?? '', $hovers, true ) ? $input['hover'] : $out['hover'];
		$out['image_ratio'] = in_array( $input['image_ratio'] ?? '', $ratios, true ) ? $input['image_ratio'] : $out['image_ratio'];
		$out['link']        = in_array( $input['link'] ?? '', $links, true ) ? $input['link'] : $out['link'];
		$out['spacing']     = in_array( $input['spacing'] ?? '', $spaces, true ) ? $input['spacing'] : $out['spacing'];

		foreach ( array( 'name_size', 'title_size', 'bio_size', 'social_size' ) as $size_key ) {
			$out[ $size_key ] = max( 0, min( 80, absint( $input[ $size_key ] ?? $out[ $size_key ] ) ) );
		}
		foreach ( array( 'name_color', 'title_color', 'bio_color', 'social_color' ) as $color_key ) {
			$out[ $color_key ] = sanitize_hex_color( $input[ $color_key ] ?? '' ) ?: '';
		}

		$out['custom_fields'] = self::parse_custom_fields( $input['custom_fields_raw'] ?? null, $input['custom_fields'] ?? array() );

		$out['archive_slug']   = sanitize_title( $input['archive_slug'] ?? 'team' ) ?: 'team';
		$out['enable_single']  = empty( $input['enable_single'] ) ? 0 : 1;
		$out['enable_archive'] = empty( $input['enable_archive'] ) ? 0 : 1;
		$out['enable_schema']   = empty( $input['enable_schema'] ) ? 0 : 1;
		$out['global_triggers'] = empty( $input['global_triggers'] ) ? 0 : 1;
		$out['custom_css']     = wp_strip_all_tags( $input['custom_css'] ?? '' );

		// Slug or visibility changes need a rewrite flush on the next load.
		update_option( 'etm_flush_needed', 1 );

		return $out;
	}

	/**
	 * Should the plugin output schema.org JSON-LD?
	 * Off when disabled in settings, auto-suppressed when AI Schema Pro is
	 * active (it owns structured data then), and filterable either way via
	 * `etm_output_schema`.
	 */
	public static function schema_enabled() {
		$enabled = (bool) self::get( 'enable_schema' );

		if ( $enabled ) {
			foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
				if ( false !== stripos( $plugin, 'ai-schema-pro' ) || false !== stripos( $plugin, 'ai_schema_pro' ) ) {
					$enabled = false;
					break;
				}
			}
		}

		return (bool) apply_filters( 'etm_output_schema', $enabled );
	}

	/**
	 * Parse the custom-fields textarea: one field per line, either
	 * "Label" or "Label | key". Returns array of key => label.
	 */
	public static function parse_custom_fields( $raw, $fallback = array() ) {
		if ( null === $raw ) {
			return is_array( $fallback ) ? $fallback : array();
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$label = sanitize_text_field( $parts[0] );
			$key   = sanitize_title( ! empty( $parts[1] ) ? $parts[1] : $parts[0] );
			if ( $key && $label && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $label;
			}
		}
		return $out;
	}

	/**
	 * Defined custom profile fields as key => label.
	 */
	public static function custom_fields() {
		$fields = self::get( 'custom_fields' );
		return apply_filters( 'etm_custom_fields', is_array( $fields ) ? $fields : array() );
	}

	/**
	 * Append field definitions discovered elsewhere (e.g. CSV import).
	 */
	public static function register_custom_fields( array $fields ) {
		$settings = (array) get_option( self::OPTION, array() );
		$existing = isset( $settings['custom_fields'] ) && is_array( $settings['custom_fields'] ) ? $settings['custom_fields'] : array();
		$merged   = $existing;
		foreach ( $fields as $key => $label ) {
			$key = sanitize_title( $key );
			if ( $key && ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = sanitize_text_field( $label ) ?: ucwords( str_replace( '-', ' ', $key ) );
			}
		}
		if ( $merged !== $existing ) {
			$settings['custom_fields'] = $merged;
			update_option( self::OPTION, $settings );
			self::$settings = null;
		}
	}

	/**
	 * Map a spacing preset (or px value) to the element-gap CSS value.
	 */
	public static function spacing_value( $spacing ) {
		$presets = array(
			'tight'  => '2px',
			'normal' => '6px',
			'spaced' => '14px',
		);
		if ( isset( $presets[ $spacing ] ) ) {
			return $presets[ $spacing ];
		}
		return is_numeric( $spacing ) ? absint( $spacing ) . 'px' : $presets['normal'];
	}

	/**
	 * CSS custom properties emitted with the front-end stylesheet.
	 */
	public static function css_vars() {
		$s    = self::get();
		$vars = '';

		$vars .= $s['text_color'] ? '--etm-text:' . $s['text_color'] . ';' : '';
		$vars .= '--etm-el-gap:' . self::spacing_value( $s['spacing'] ) . ';';
		$vars .= '--etm-social-size:' . max( 10, (int) $s['social_size'] ) . 'px;';

		foreach ( array( 'name', 'title', 'bio' ) as $el ) {
			if ( (int) $s[ $el . '_size' ] > 0 ) {
				$vars .= '--etm-' . $el . '-size:' . (int) $s[ $el . '_size' ] . 'px;';
			}
			if ( $s[ $el . '_color' ] ) {
				$vars .= '--etm-' . $el . '-color:' . $s[ $el . '_color' ] . ';';
			}
		}
		if ( $s['social_color'] ) {
			$vars .= '--etm-social-color:' . $s['social_color'] . ';';
		}

		$css = sprintf(
			'.etm{--etm-accent:%1$s;--etm-card-bg:%2$s;--etm-radius:%3$dpx;--etm-gap:%4$dpx;%5$s}',
			$s['accent'],
			$s['card_bg'],
			$s['radius'],
			$s['gap'],
			$vars
		);
		if ( ! empty( $s['custom_css'] ) ) {
			$css .= "\n" . $s['custom_css'];
		}
		return $css;
	}

	public static function render_page() {
		$s = self::get();
		?>
		<div class="wrap etm-settings-wrap">
			<h1><?php esc_html_e( 'Team Settings', 'emotio-team' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Site-wide defaults. Every option can be overridden per placement via the shortcode, block or WPBakery element.', 'emotio-team' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'etm_settings_group' ); ?>
				<h2><?php esc_html_e( 'Design', 'emotio-team' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="etm-accent"><?php esc_html_e( 'Accent colour', 'emotio-team' ); ?></label></th>
						<td><input type="color" id="etm-accent" name="<?php echo esc_attr( self::OPTION ); ?>[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>">
						<p class="description"><?php esc_html_e( 'Used for filters, links, social icons and highlights. Set this to your Salient accent colour for a seamless match.', 'emotio-team' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="etm-card-bg"><?php esc_html_e( 'Card background', 'emotio-team' ); ?></label></th>
						<td><input type="color" id="etm-card-bg" name="<?php echo esc_attr( self::OPTION ); ?>[card_bg]" value="<?php echo esc_attr( $s['card_bg'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="etm-radius"><?php esc_html_e( 'Corner radius (px)', 'emotio-team' ); ?></label></th>
						<td><input type="number" min="0" max="60" id="etm-radius" name="<?php echo esc_attr( self::OPTION ); ?>[radius]" value="<?php echo esc_attr( $s['radius'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="etm-gap"><?php esc_html_e( 'Grid gap (px)', 'emotio-team' ); ?></label></th>
						<td><input type="number" min="0" max="80" id="etm-gap" name="<?php echo esc_attr( self::OPTION ); ?>[gap]" value="<?php echo esc_attr( $s['gap'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="etm-style"><?php esc_html_e( 'Default card style', 'emotio-team' ); ?></label></th>
						<td>
							<select id="etm-style" name="<?php echo esc_attr( self::OPTION ); ?>[style]">
								<?php foreach ( array( 'cards' => __( 'Cards', 'emotio-team' ), 'minimal' => __( 'Minimal', 'emotio-team' ), 'overlay' => __( 'Image overlay', 'emotio-team' ), 'circle' => __( 'Circle portrait', 'emotio-team' ) ) as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['style'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="etm-hover"><?php esc_html_e( 'Default hover effect', 'emotio-team' ); ?></label></th>
						<td>
							<select id="etm-hover" name="<?php echo esc_attr( self::OPTION ); ?>[hover]">
								<?php foreach ( array( 'lift' => __( 'Lift', 'emotio-team' ), 'zoom' => __( 'Image zoom', 'emotio-team' ), 'swap' => __( 'Swap to hover photo', 'emotio-team' ), 'grayscale' => __( 'Grayscale to colour', 'emotio-team' ), 'none' => __( 'None', 'emotio-team' ) ) as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['hover'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="etm-ratio"><?php esc_html_e( 'Default photo ratio', 'emotio-team' ); ?></label></th>
						<td>
							<select id="etm-ratio" name="<?php echo esc_attr( self::OPTION ); ?>[image_ratio]">
								<?php foreach ( array( '1-1' => '1:1', '3-4' => '3:4', '2-3' => '2:3', '4-3' => '4:3', '16-9' => '16:9' ) as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['image_ratio'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="etm-columns"><?php esc_html_e( 'Default columns', 'emotio-team' ); ?></label></th>
						<td><input type="number" min="1" max="6" id="etm-columns" name="<?php echo esc_attr( self::OPTION ); ?>[columns]" value="<?php echo esc_attr( $s['columns'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="etm-link"><?php esc_html_e( 'Card click behaviour', 'emotio-team' ); ?></label></th>
						<td>
							<select id="etm-link" name="<?php echo esc_attr( self::OPTION ); ?>[link]">
								<option value="modal" <?php selected( $s['link'], 'modal' ); ?>><?php esc_html_e( 'Open profile modal', 'emotio-team' ); ?></option>
								<option value="panel" <?php selected( $s['link'], 'panel' ); ?>><?php esc_html_e( 'Slide-out profile panel', 'emotio-team' ); ?></option>
								<option value="page" <?php selected( $s['link'], 'page' ); ?>><?php esc_html_e( 'Go to profile page', 'emotio-team' ); ?></option>
								<option value="none" <?php selected( $s['link'], 'none' ); ?>><?php esc_html_e( 'Not clickable', 'emotio-team' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="etm-spacing"><?php esc_html_e( 'Element spacing', 'emotio-team' ); ?></label></th>
						<td>
							<select id="etm-spacing" name="<?php echo esc_attr( self::OPTION ); ?>[spacing]">
								<option value="tight" <?php selected( $s['spacing'], 'tight' ); ?>><?php esc_html_e( 'Tight', 'emotio-team' ); ?></option>
								<option value="normal" <?php selected( $s['spacing'], 'normal' ); ?>><?php esc_html_e( 'Normal', 'emotio-team' ); ?></option>
								<option value="spaced" <?php selected( $s['spacing'], 'spaced' ); ?>><?php esc_html_e( 'Spaced', 'emotio-team' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'The gap between name, title, snippet and social icons on each card. Per-placement: spacing="tight|normal|spaced" or an exact pixel value.', 'emotio-team' ); ?></p>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Custom profile fields', 'emotio-team' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Extra fields every team member gets — qualifications, languages, office days, anything. One per line as "Label" or "Label | key". They appear on the member edit screen, in profile modals/panels and on profile pages, and travel through CSV import/export as cf_key columns.', 'emotio-team' ); ?></p>
				<?php
				$cf_lines = array();
				foreach ( (array) $s['custom_fields'] as $cf_key => $cf_label ) {
					$cf_lines[] = $cf_label . ' | ' . $cf_key;
				}
				?>
				<textarea name="<?php echo esc_attr( self::OPTION ); ?>[custom_fields_raw]" rows="5" class="large-text code" placeholder="<?php esc_attr_e( "Qualifications\nLanguages | languages\nOffice days", 'emotio-team' ); ?>"><?php echo esc_textarea( implode( "\n", $cf_lines ) ); ?></textarea>
				<h2><?php esc_html_e( 'Typography & element colours', 'emotio-team' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Size 0 / empty colour = inherit from the theme (recommended for a native Salient look). Every value can also be overridden per placement via shortcode attributes (name_size, name_color, title_size, title_color, bio_size, bio_color, social_size, social_color).', 'emotio-team' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$type_rows = array(
						'name'   => __( 'Name', 'emotio-team' ),
						'title'  => __( 'Job title', 'emotio-team' ),
						'bio'    => __( 'Snippet / bio', 'emotio-team' ),
						'social' => __( 'Social icons', 'emotio-team' ),
					);
					foreach ( $type_rows as $el => $label ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<label><?php esc_html_e( 'Size (px)', 'emotio-team' ); ?>
									<input type="number" min="0" max="80" style="width:80px" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $el ); ?>_size]" value="<?php echo esc_attr( $s[ $el . '_size' ] ); ?>">
								</label>
								&nbsp;&nbsp;
								<label><?php esc_html_e( 'Colour', 'emotio-team' ); ?>
									<input type="text" class="code" style="width:100px" placeholder="<?php esc_attr_e( 'inherit', 'emotio-team' ); ?>" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $el ); ?>_color]" value="<?php echo esc_attr( $s[ $el . '_color' ] ); ?>">
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<h2><?php esc_html_e( 'Pages & SEO', 'emotio-team' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="etm-slug"><?php esc_html_e( 'Team URL slug', 'emotio-team' ); ?></label></th>
						<td><input type="text" id="etm-slug" name="<?php echo esc_attr( self::OPTION ); ?>[archive_slug]" value="<?php echo esc_attr( $s['archive_slug'] ); ?>" class="regular-text">
						<p class="description"><?php echo esc_html( sprintf( __( 'Profiles live at /%s/name/ and the team listing at /%s/.', 'emotio-team' ), $s['archive_slug'], $s['archive_slug'] ) ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Individual profile pages', 'emotio-team' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enable_single]" value="1" <?php checked( $s['enable_single'], 1 ); ?>> <?php esc_html_e( 'Give each team member their own page', 'emotio-team' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Team archive page', 'emotio-team' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enable_archive]" value="1" <?php checked( $s['enable_archive'], 1 ); ?>> <?php esc_html_e( 'Enable the built-in searchable team listing page', 'emotio-team' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Profile triggers', 'emotio-team' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[global_triggers]" value="1" <?php checked( $s['global_triggers'], 1 ); ?>> <?php esc_html_e( 'Let any element open a team profile', 'emotio-team' ); ?></label>
						<p class="description"><?php esc_html_e( 'Give any element the class etm-profile-ID (modal) or etm-panel-ID (slide-out), or link it to #etm-profile-ID — the member ID is shown in the Team list. Salient Button, Image and Icon elements also get a "Team Profile" tab in their settings. Untick to load trigger assets only on pages with team layouts.', 'emotio-team' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Structured data', 'emotio-team' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enable_schema]" value="1" <?php checked( $s['enable_schema'], 1 ); ?>> <?php esc_html_e( 'Output schema.org Person / ItemList JSON-LD for SEO', 'emotio-team' ); ?></label>
						<p class="description"><?php esc_html_e( 'Automatically suppressed while AI Schema Pro is active, so structured data is never duplicated.', 'emotio-team' ); ?></p></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Custom CSS', 'emotio-team' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Applied wherever team layouts render. All plugin styles hang off CSS variables (--etm-accent, --etm-radius, --etm-gap, --etm-card-bg) so overrides are easy.', 'emotio-team' ); ?></p>
				<textarea name="<?php echo esc_attr( self::OPTION ); ?>[custom_css]" rows="8" class="large-text code"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Shortcode reference', 'emotio-team' ); ?></h2>
			<p><code>[emotio_team layout="grid" columns="3" style="cards" hover="lift" department="design" show_filter="yes" show_search="yes"]</code></p>
			<p class="description"><?php esc_html_e( 'Attributes: layout (grid|slider|list), slider_style (drag|paged), columns (1–6), style (cards|minimal|overlay|circle), hover (lift|zoom|swap|grayscale|none), image_ratio (1-1|3-4|2-3|4-3|16-9), department, tag, ids, exclude, limit, orderby (menu_order|title|date|rand), order (ASC|DESC), link (modal|panel|page|none), show_filter, show_search, show_social, show_bio, accent, gap, spacing (tight|normal|spaced|px), name_size, name_color, title_size, title_color, bio_size, bio_color, social_size, social_color, autoplay, autoplay_speed.', 'emotio-team' ); ?></p>
		</div>
		<?php
	}
}
