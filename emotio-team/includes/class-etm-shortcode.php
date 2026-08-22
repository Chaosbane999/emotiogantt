<?php
/**
 * Front-end render engine: [emotio_team] shortcode, grid / slider / list
 * layouts, live search + department filters, profile modals and schema.org
 * structured data.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Shortcode {

	/** @var int Instance counter for unique element ids. */
	protected static $instance = 0;

	public static function init() {
		add_shortcode( 'emotio_team', array( __CLASS__, 'render' ) );
		add_shortcode( 'emotio_team_member', array( __CLASS__, 'render_member' ) );
		add_shortcode( 'emotio_team_search', array( __CLASS__, 'render_search' ) );
		add_shortcode( 'emotio_team_filter', array( __CLASS__, 'render_filter' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * [emotio_team_member id="…"] — one member's card, droppable anywhere.
	 */
	public static function render_member( $atts ) {
		$a = shortcode_atts(
			array(
				'id'          => '',
				'style'       => ETM_Settings::get( 'style' ),
				'hover'       => ETM_Settings::get( 'hover' ),
				'image_ratio' => ETM_Settings::get( 'image_ratio' ),
				'link'        => ETM_Settings::get( 'link' ),
				'show_social' => 'yes',
				'show_bio'    => 'yes',
				'accent'      => '',
				'class'       => '',
			),
			(array) $atts,
			'emotio_team_member'
		);
		if ( ! absint( $a['id'] ) ) {
			return '';
		}
		return self::render(
			array(
				'ids'         => absint( $a['id'] ),
				'limit'       => 1,
				'columns'     => 1,
				'layout'      => 'grid',
				'style'       => $a['style'],
				'hover'       => $a['hover'],
				'image_ratio' => $a['image_ratio'],
				'link'        => $a['link'],
				'show_social' => $a['show_social'],
				'show_bio'    => $a['show_bio'],
				'accent'      => $a['accent'],
				'class'       => trim( 'etm--one ' . $a['class'] ),
			)
		);
	}

	/**
	 * [emotio_team_search] — a standalone live-search box that drives a
	 * team layout elsewhere on the page (first one by default, or a CSS
	 * selector via target="").
	 */
	public static function render_search( $atts ) {
		$a = shortcode_atts(
			array(
				'target'      => '',
				'placeholder' => __( 'Search name, role, skill…', 'emotio-team' ),
			),
			(array) $atts,
			'emotio_team_search'
		);
		self::enqueue_assets();
		ob_start();
		?>
		<div class="etm etm-remote" data-etm-remote-search data-target="<?php echo esc_attr( $a['target'] ); ?>">
			<label class="etm-search">
				<?php echo self::icon( 'search' ); // phpcs:ignore ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Search the team', 'emotio-team' ); ?></span>
				<input type="search" placeholder="<?php echo esc_attr( $a['placeholder'] ); ?>" autocomplete="off">
			</label>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [emotio_team_filter] — standalone department chips driving a team
	 * layout elsewhere on the page.
	 */
	public static function render_filter( $atts ) {
		$a = shortcode_atts(
			array(
				'target'      => '',
				'departments' => '',
			),
			(array) $atts,
			'emotio_team_filter'
		);

		$args = array(
			'taxonomy'   => ETM_CPT::TAX_DEPT,
			'hide_empty' => true,
		);
		if ( $a['departments'] ) {
			$args['slug'] = array_filter( array_map( 'sanitize_title', explode( ',', $a['departments'] ) ) );
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}

		self::enqueue_assets();
		ob_start();
		?>
		<div class="etm etm-remote" data-etm-remote-filter data-target="<?php echo esc_attr( $a['target'] ); ?>">
			<div class="etm-filters" role="group" aria-label="<?php esc_attr_e( 'Filter by department', 'emotio-team' ); ?>">
				<button type="button" class="etm-chip is-active" data-filter="*" aria-pressed="true"><?php esc_html_e( 'All', 'emotio-team' ); ?></button>
				<?php foreach ( $terms as $term ) : ?>
					<button type="button" class="etm-chip" data-filter="<?php echo esc_attr( $term->slug ); ?>" aria-pressed="false"><?php echo esc_html( $term->name ); ?></button>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * A member's full profile markup (used by the AJAX trigger endpoint).
	 */
	public static function detail_html( $post ) {
		ob_start();
		self::detail( $post, ETM_Meta::socials( $post->ID ) );
		return ob_get_clean();
	}

	public static function register_assets() {
		wp_register_style( 'etm-team', ETM_URL . 'assets/css/team.css', array(), ETM_VERSION );
		wp_add_inline_style( 'etm-team', ETM_Settings::css_vars() );
		wp_register_script( 'etm-team', ETM_URL . 'assets/js/team.js', array(), ETM_VERSION, true );
		wp_localize_script(
			'etm-team',
			'etmI18n',
			array(
				'close'      => __( 'Close', 'emotio-team' ),
				'noResults'  => __( 'No team members match your search.', 'emotio-team' ),
				'prev'       => __( 'Previous', 'emotio-team' ),
				'next'       => __( 'Next', 'emotio-team' ),
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	public static function enqueue_assets() {
		wp_enqueue_style( 'etm-team' );
		wp_enqueue_script( 'etm-team' );
	}

	/**
	 * Shortcode defaults, seeded from the settings page.
	 */
	public static function defaults() {
		return array(
			'layout'         => 'grid',
			'columns'        => ETM_Settings::get( 'columns' ),
			'style'          => ETM_Settings::get( 'style' ),
			'hover'          => ETM_Settings::get( 'hover' ),
			'image_ratio'    => ETM_Settings::get( 'image_ratio' ),
			'link'           => ETM_Settings::get( 'link' ),
			'department'     => '',
			'relation'       => 'OR',
			'tag'            => '',
			'ids'            => '',
			'exclude'        => '',
			'featured'       => '',
			'limit'          => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'show_filter'    => 'no',
			'show_search'    => 'no',
			'show_social'    => 'yes',
			'show_bio'       => 'no',
			'show_title'     => 'yes',
			'show_email'     => 'no',
			'show_phone'     => 'no',
			'show_location'  => 'no',
			'show_department' => 'no',
			'columns_tablet' => '',
			'columns_mobile' => '',
			'group_by'       => '',
			'accent'         => '',
			'gap'            => '',
			'spacing'        => ETM_Settings::get( 'spacing' ),
			'slider_style'   => 'drag',
			'name_size'      => '',
			'name_color'     => '',
			'title_size'     => '',
			'title_color'    => '',
			'bio_size'       => '',
			'bio_color'      => '',
			'social_size'    => '',
			'social_color'   => '',
			'autoplay'       => 'no',
			'autoplay_speed' => 5000,
			'class'          => '',
		);
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$a = shortcode_atts( self::defaults(), (array) $atts, 'emotio_team' );

		$a['layout']   = in_array( $a['layout'], array( 'grid', 'slider', 'list', 'spotlight' ), true ) ? $a['layout'] : 'grid';
		$a['group_by'] = in_array( $a['group_by'], array( 'department', 'yes', '1' ), true ) ? 'department' : '';
		$a['style']   = in_array( $a['style'], array( 'cards', 'minimal', 'overlay', 'circle' ), true ) ? $a['style'] : 'cards';
		$a['hover']   = in_array( $a['hover'], array( 'lift', 'zoom', 'swap', 'grayscale', 'none' ), true ) ? $a['hover'] : 'lift';
		$a['link']         = in_array( $a['link'], array( 'modal', 'panel', 'page', 'custom', 'none' ), true ) ? $a['link'] : 'modal';
		$a['relation']     = 'AND' === strtoupper( $a['relation'] ) ? 'AND' : 'OR';
		$a['slider_style'] = in_array( $a['slider_style'], array( 'drag', 'paged' ), true ) ? $a['slider_style'] : 'drag';
		$a['columns']      = max( 1, min( 6, absint( $a['columns'] ) ) );

		if ( 'page' === $a['link'] && ! ETM_Settings::get( 'enable_single' ) ) {
			$a['link'] = 'modal';
		}

		$members = self::query( $a );
		if ( ! $members->have_posts() ) {
			return '<div class="etm etm--empty">' . esc_html__( 'No team members found.', 'emotio-team' ) . '</div>';
		}

		self::enqueue_assets();
		self::$instance++;
		$id = 'etm-' . self::$instance;

		$is_slider = 'slider' === $a['layout'];
		$classes   = array(
			'etm',
			'etm--' . $a['layout'],
			'etm--style-' . $a['style'],
			'etm--hover-' . $a['hover'],
			'etm--ratio-' . $a['image_ratio'],
			'etm--cols-' . $a['columns'],
		);
		if ( defined( 'NECTAR_THEME_NAME' ) || wp_get_theme()->get_template() === 'salient' ) {
			$classes[] = 'etm--salient';
		}
		if ( $is_slider && 'drag' === $a['slider_style'] ) {
			$classes[] = 'etm--drag';
		}
		$cols_tablet = ( '' !== $a['columns_tablet'] && is_numeric( $a['columns_tablet'] ) ) ? max( 1, min( 6, (int) $a['columns_tablet'] ) ) : 0;
		$cols_mobile = ( '' !== $a['columns_mobile'] && is_numeric( $a['columns_mobile'] ) ) ? max( 1, min( 4, (int) $a['columns_mobile'] ) ) : 0;
		if ( $cols_tablet ) {
			$classes[] = 'etm--has-cols-t';
		}
		if ( $cols_mobile ) {
			$classes[] = 'etm--has-cols-m';
		}
		if ( $a['class'] ) {
			$classes[] = sanitize_html_class( $a['class'] );
		}

		$style_attr = '';
		if ( self::css_color( $a['accent'] ) ) {
			$accent      = self::css_color( $a['accent'] );
			$style_attr .= '--etm-accent:' . $accent . ';';
			$style_attr .= '--etm-on-accent:' . ETM_Settings::contrast_color( $accent ) . ';';
			$accent_lum  = ETM_Settings::luminance( $accent );
			$card_lum    = ETM_Settings::luminance( ETM_Settings::get( 'card_bg' ) );
			$style_attr .= ( null !== $accent_lum && null !== $card_lum && $accent_lum > 200 && $card_lum > 200 ) ? '--etm-heading-accent:#1f2937;' : '--etm-heading-accent:' . $accent . ';';
		}
		if ( '' !== $a['gap'] ) {
			$style_attr .= '--etm-gap:' . absint( $a['gap'] ) . 'px;';
		}
		if ( ETM_Settings::get( 'spacing' ) !== $a['spacing'] ) {
			$style_attr .= '--etm-el-gap:' . ETM_Settings::spacing_value( $a['spacing'] ) . ';';
		}
		if ( $cols_tablet ) {
			$style_attr .= '--etm-cols-tablet:' . $cols_tablet . ';';
		}
		if ( $cols_mobile ) {
			$style_attr .= '--etm-cols-mobile:' . $cols_mobile . ';';
		}
		foreach ( array( 'name', 'title', 'bio', 'social' ) as $el ) {
			if ( '' !== $a[ $el . '_size' ] && is_numeric( $a[ $el . '_size' ] ) && (int) $a[ $el . '_size' ] > 0 ) {
				$style_attr .= '--etm-' . $el . '-size:' . absint( $a[ $el . '_size' ] ) . 'px;';
			}
			if ( self::css_color( $a[ $el . '_color' ] ) ) {
				$style_attr .= '--etm-' . $el . '-color:' . self::css_color( $a[ $el . '_color' ] ) . ';';
			}
		}

		$schema_people = array();

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			<?php echo $style_attr ? 'style="' . esc_attr( $style_attr ) . '"' : ''; ?>
			data-etm
			data-layout="<?php echo esc_attr( $a['layout'] ); ?>"
			data-columns="<?php echo esc_attr( $a['columns'] ); ?>"
			data-link="<?php echo esc_attr( $a['link'] ); ?>"
			<?php if ( $is_slider ) : ?>
				data-slider="<?php echo esc_attr( $a['slider_style'] ); ?>"
			<?php endif; ?>
			<?php if ( $is_slider && 'yes' === $a['autoplay'] ) : ?>
				data-autoplay="<?php echo esc_attr( max( 2000, absint( $a['autoplay_speed'] ) ) ); ?>"
			<?php endif; ?>>

			<?php self::toolbar( $a, $members ); ?>

			<?php if ( $is_slider && 'drag' === $a['slider_style'] ) : ?>
				<div class="etm-slider">
					<button type="button" class="etm-arrow etm-arrow--prev" aria-label="<?php esc_attr_e( 'Previous team members', 'emotio-team' ); ?>"><?php echo self::icon( 'chevron-left' ); // phpcs:ignore ?></button>
					<div class="etm-viewport" tabindex="0" aria-label="<?php esc_attr_e( 'Team members — drag to browse', 'emotio-team' ); ?>">
						<div class="etm-track etm-track--drag">
							<?php self::cards( $members, $a, $schema_people ); ?>
						</div>
					</div>
					<button type="button" class="etm-arrow etm-arrow--next" aria-label="<?php esc_attr_e( 'Next team members', 'emotio-team' ); ?>"><?php echo self::icon( 'chevron-right' ); // phpcs:ignore ?></button>
				</div>
			<?php elseif ( $is_slider ) : ?>
				<div class="etm-slider">
					<button type="button" class="etm-arrow etm-arrow--prev" aria-label="<?php esc_attr_e( 'Previous team members', 'emotio-team' ); ?>"><?php echo self::icon( 'chevron-left' ); // phpcs:ignore ?></button>
					<div class="etm-track" tabindex="0" aria-label="<?php esc_attr_e( 'Team members carousel', 'emotio-team' ); ?>">
						<?php self::cards( $members, $a, $schema_people ); ?>
					</div>
					<button type="button" class="etm-arrow etm-arrow--next" aria-label="<?php esc_attr_e( 'Next team members', 'emotio-team' ); ?>"><?php echo self::icon( 'chevron-right' ); // phpcs:ignore ?></button>
					<div class="etm-dots" role="tablist"></div>
				</div>
			<?php elseif ( 'spotlight' === $a['layout'] ) : ?>
				<?php self::spotlight( $members, $a, $schema_people ); ?>
			<?php elseif ( 'department' === $a['group_by'] ) : ?>
				<?php self::grouped( $members, $a, $schema_people ); ?>
			<?php else : ?>
				<div class="etm-grid">
					<?php self::cards( $members, $a, $schema_people ); ?>
				</div>
			<?php endif; ?>

			<p class="etm-no-results" hidden><?php esc_html_e( 'No team members match your search.', 'emotio-team' ); ?></p>
		</div>
		<?php
		if ( ETM_Settings::schema_enabled() && $schema_people ) {
			echo self::schema( $schema_people ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		wp_reset_postdata();

		return ob_get_clean();
	}

	/**
	 * Sanitize a colour value: hex or rgb()/rgba() (WPBakery pickers emit
	 * both). Returns '' when the value is not a safe colour.
	 */
	protected static function css_color( $value ) {
		return ETM_Settings::normalize_color( $value );
	}

	/**
	 * Build the members query from shortcode attributes.
	 */
	protected static function query( $a ) {
		$args = array(
			'post_type'      => ETM_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $a['limit'],
			'order'          => 'DESC' === strtoupper( $a['order'] ) ? 'DESC' : 'ASC',
		);

		switch ( $a['orderby'] ) {
			case 'title':
				$args['orderby'] = 'title';
				break;
			case 'date':
				$args['orderby'] = 'date';
				break;
			case 'rand':
				$args['orderby'] = 'rand';
				break;
			case 'id':
				$args['orderby'] = 'ID';
				break;
			case 'job_title':
				$args['meta_key'] = '_etm_job_title';
				$args['orderby']  = array(
					'meta_value' => $args['order'],
					'title'      => 'ASC',
				);
				break;
			default:
				$args['orderby'] = array(
					'menu_order' => $args['order'],
					'title'      => 'ASC',
				);
		}

		$tax_query = array();
		if ( $a['department'] ) {
			$tax_query[] = array(
				'taxonomy' => ETM_CPT::TAX_DEPT,
				'field'    => 'slug',
				'terms'    => array_filter( array_map( 'sanitize_title', explode( ',', $a['department'] ) ) ),
				// AND = member must belong to every listed department.
				'operator' => 'AND' === $a['relation'] ? 'AND' : 'IN',
			);
		}
		if ( $a['tag'] ) {
			$tax_query[] = array(
				'taxonomy' => ETM_CPT::TAX_TAG,
				'field'    => 'slug',
				'terms'    => array_filter( array_map( 'sanitize_title', explode( ',', $a['tag'] ) ) ),
			);
		}
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query;
		}

		if ( $a['ids'] ) {
			$args['post__in'] = array_filter( array_map( 'absint', explode( ',', $a['ids'] ) ) );
			$args['orderby']  = 'post__in';
		}
		if ( $a['exclude'] ) {
			$args['post__not_in'] = array_filter( array_map( 'absint', explode( ',', $a['exclude'] ) ) );
		}
		if ( 'yes' === $a['featured'] ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_etm_featured',
					'value' => 1,
				),
			);
		}

		return new WP_Query( apply_filters( 'etm_query_args', $args, $a ) );
	}

	/**
	 * Search box + department filter chips.
	 */
	protected static function toolbar( $a, WP_Query $members ) {
		$show_search = 'yes' === $a['show_search'];
		$show_filter = 'yes' === $a['show_filter'];
		if ( ! $show_search && ! $show_filter ) {
			return;
		}

		$departments = array();
		if ( $show_filter ) {
			foreach ( $members->posts as $post ) {
				$terms = get_the_terms( $post, ETM_CPT::TAX_DEPT );
				if ( $terms && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$departments[ $term->slug ] = $term->name;
					}
				}
			}
			asort( $departments );
		}
		?>
		<div class="etm-toolbar">
			<?php if ( $show_filter && $departments ) : ?>
				<div class="etm-filters" role="group" aria-label="<?php esc_attr_e( 'Filter by department', 'emotio-team' ); ?>">
					<button type="button" class="etm-chip is-active" data-filter="*" aria-pressed="true"><?php esc_html_e( 'All', 'emotio-team' ); ?></button>
					<?php foreach ( $departments as $slug => $name ) : ?>
						<button type="button" class="etm-chip" data-filter="<?php echo esc_attr( $slug ); ?>" aria-pressed="false"><?php echo esc_html( $name ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $show_search ) : ?>
				<label class="etm-search">
					<?php echo self::icon( 'search' ); // phpcs:ignore ?>
					<span class="screen-reader-text"><?php esc_html_e( 'Search the team', 'emotio-team' ); ?></span>
					<input type="search" placeholder="<?php esc_attr_e( 'Search name, role, skill…', 'emotio-team' ); ?>" autocomplete="off">
				</label>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render all member cards.
	 */
	protected static function cards( WP_Query $members, $a, &$schema_people ) {
		while ( $members->have_posts() ) {
			$members->the_post();
			self::card( get_post(), $a, $schema_people );
		}
	}

	/**
	 * Spotlight layout: featured members render as large horizontal cards,
	 * everyone else follows in the normal grid.
	 */
	protected static function spotlight( WP_Query $members, $a, &$schema_people ) {
		$featured = array();
		$rest     = array();
		foreach ( $members->posts as $post ) {
			if ( ETM_Meta::get( $post->ID, 'featured' ) ) {
				$featured[] = $post;
			} else {
				$rest[] = $post;
			}
		}
		// Nobody flagged as featured: spotlight the first member.
		if ( ! $featured && $rest ) {
			$featured[] = array_shift( $rest );
		}

		echo '<div class="etm-spotlight">';
		foreach ( $featured as $post ) {
			self::spotlight_card( $post, $a, $schema_people );
		}
		echo '</div>';

		if ( $rest ) {
			echo '<div class="etm-grid">';
			foreach ( $rest as $post ) {
				self::card( $post, $a, $schema_people );
			}
			echo '</div>';
		}
	}

	/**
	 * One large spotlight card.
	 */
	protected static function spotlight_card( $post, $a, &$schema_people ) {
		$id        = $post->ID;
		$name      = get_the_title( $post );
		$job_title = ETM_Meta::get( $id, 'job_title' );
		$socials   = ETM_Meta::socials( $id );
		$permalink = get_permalink( $post );

		$bio = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( $post->post_content );
		$bio = $bio ? wp_trim_words( $bio, 48 ) : '';

		$dept_terms = get_the_terms( $post, ETM_CPT::TAX_DEPT );
		$dept_slugs = ( $dept_terms && ! is_wp_error( $dept_terms ) ) ? wp_list_pluck( $dept_terms, 'slug' ) : array();
		$dept_names = ( $dept_terms && ! is_wp_error( $dept_terms ) ) ? wp_list_pluck( $dept_terms, 'name' ) : array();

		$schema_people[] = array(
			'name'  => $name,
			'title' => $job_title,
			'url'   => ETM_Settings::get( 'enable_single' ) ? $permalink : '',
			'image' => get_the_post_thumbnail_url( $post, 'large' ),
			'email' => ETM_Meta::get( $id, 'email' ),
		);

		$clickable = 'none' !== $a['link'];
		$is_modal  = in_array( $a['link'], array( 'modal', 'panel' ), true );
		$hit_url   = $permalink;
		$external  = false;
		if ( 'custom' === $a['link'] ) {
			$custom_url = ETM_Meta::get( $id, 'profile_url' );
			if ( $custom_url ) {
				$hit_url  = $custom_url;
				$external = true;
			}
		}
		?>
		<div class="etm-item etm-item--spotlight"
			data-search="<?php echo esc_attr( strtolower( implode( ' ', array_filter( array_merge( array( $name, $job_title ), $dept_names ) ) ) ) ); ?>"
			data-departments="<?php echo esc_attr( implode( ' ', $dept_slugs ) ); ?>"
			data-name="<?php echo esc_attr( $name ); ?>"
			data-member="<?php echo esc_attr( $id ); ?>"
			data-photo="<?php echo esc_url( get_the_post_thumbnail_url( $post, 'large' ) ?: '' ); ?>">
			<article class="etm-card etm-card--spotlight" <?php echo $is_modal ? 'data-modal-source' : ''; ?>>
				<div class="etm-media">
					<?php
					if ( has_post_thumbnail( $post ) ) {
						echo get_the_post_thumbnail( $post, 'large', array( 'class' => 'etm-photo', 'loading' => 'lazy' ) );
					} else {
						echo '<div class="etm-photo etm-photo--placeholder" aria-hidden="true">' . self::icon( 'user' ) . '</div>'; // phpcs:ignore
					}
					?>
					<?php if ( $clickable ) : ?>
						<?php if ( $is_modal ) : ?>
							<button type="button" class="etm-hit" data-etm-open aria-haspopup="dialog">
								<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: member name */ __( 'View profile of %s', 'emotio-team' ), $name ) ); ?></span>
							</button>
						<?php else : ?>
							<a class="etm-hit" href="<?php echo esc_url( $hit_url ); ?>" <?php echo $external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
								<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: member name */ __( 'View profile of %s', 'emotio-team' ), $name ) ); ?></span>
							</a>
						<?php endif; ?>
					<?php endif; ?>
				</div>
				<div class="etm-body">
					<?php if ( $dept_names ) : ?><p class="etm-spotlight-dept"><?php echo esc_html( implode( ' · ', $dept_names ) ); ?></p><?php endif; ?>
					<h3 class="etm-name"><?php echo self::name_link( $name, $hit_url, $a, $external ); // phpcs:ignore ?></h3>
					<?php if ( $job_title && 'yes' === $a['show_title'] ) : ?><p class="etm-role"><?php echo esc_html( $job_title ); ?></p><?php endif; ?>
					<?php if ( $bio ) : ?><p class="etm-bio"><?php echo esc_html( $bio ); ?></p><?php endif; ?>
					<?php if ( 'no' !== $a['show_social'] && $socials ) : ?><?php self::social_row( $socials ); ?><?php endif; ?>
				</div>
			</article>
			<?php if ( $is_modal ) : ?>
				<template class="etm-detail"><?php self::detail( $post, $socials ); ?></template>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Grid grouped into one section per department (members without a
	 * department land in a trailing "Team" group). A member in several
	 * departments appears in each of them.
	 */
	protected static function grouped( WP_Query $members, $a, &$schema_people ) {
		$groups = array();
		$loose  = array();

		foreach ( $members->posts as $post ) {
			$terms = get_the_terms( $post, ETM_CPT::TAX_DEPT );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( ! isset( $groups[ $term->term_id ] ) ) {
						$groups[ $term->term_id ] = array(
							'name'  => $term->name,
							'posts' => array(),
						);
					}
					$groups[ $term->term_id ]['posts'][] = $post;
				}
			} else {
				$loose[] = $post;
			}
		}

		uasort(
			$groups,
			function ( $x, $y ) {
				return strcasecmp( $x['name'], $y['name'] );
			}
		);
		if ( $loose ) {
			$groups['_loose'] = array(
				'name'  => __( 'Team', 'emotio-team' ),
				'posts' => $loose,
			);
		}

		foreach ( $groups as $group ) {
			echo '<section class="etm-group">';
			echo '<h3 class="etm-group-title">' . esc_html( $group['name'] ) . '</h3>';
			echo '<div class="etm-grid">';
			foreach ( $group['posts'] as $post ) {
				self::card( $post, $a, $schema_people );
			}
			echo '</div></section>';
		}
	}

	/**
	 * Render a single member card + hidden modal detail template.
	 */
	protected static function card( $post, $a, &$schema_people ) {
		$id        = $post->ID;
		$name      = get_the_title( $post );
		$job_title = ETM_Meta::get( $id, 'job_title' );
		$excerpt   = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
		$socials   = ETM_Meta::socials( $id );
		$permalink = get_permalink( $post );

		$dept_terms = get_the_terms( $post, ETM_CPT::TAX_DEPT );
		$dept_slugs = array();
		$dept_names = array();
		if ( $dept_terms && ! is_wp_error( $dept_terms ) ) {
			foreach ( $dept_terms as $t ) {
				$dept_slugs[] = $t->slug;
				$dept_names[] = $t->name;
			}
		}
		$tag_terms = get_the_terms( $post, ETM_CPT::TAX_TAG );
		$tag_names = ( $tag_terms && ! is_wp_error( $tag_terms ) ) ? wp_list_pluck( $tag_terms, 'name' ) : array();

		$search_blob = strtolower( implode( ' ', array_filter( array_merge( array( $name, $job_title ), $dept_names, $tag_names ) ) ) );

		$schema_people[] = array(
			'name'  => $name,
			'title' => $job_title,
			'url'   => ETM_Settings::get( 'enable_single' ) ? $permalink : '',
			'image' => get_the_post_thumbnail_url( $post, 'large' ),
			'email' => ETM_Meta::get( $id, 'email' ),
		);

		$clickable = 'none' !== $a['link'];
		$is_modal  = in_array( $a['link'], array( 'modal', 'panel' ), true );
		$hit_url   = $permalink;
		$external  = false;
		if ( 'custom' === $a['link'] ) {
			$custom_url = ETM_Meta::get( $id, 'profile_url' );
			if ( $custom_url ) {
				$hit_url  = $custom_url;
				$external = true;
			}
		}
		?>
		<div class="etm-item"
			data-search="<?php echo esc_attr( $search_blob ); ?>"
			data-departments="<?php echo esc_attr( implode( ' ', $dept_slugs ) ); ?>"
			data-name="<?php echo esc_attr( $name ); ?>"
			data-member="<?php echo esc_attr( $id ); ?>"
			data-photo="<?php echo esc_url( get_the_post_thumbnail_url( $post, 'large' ) ?: '' ); ?>">
			<article class="etm-card" <?php echo $is_modal ? 'data-modal-source' : ''; ?>>
				<div class="etm-media">
					<?php
					if ( has_post_thumbnail( $post ) ) {
						echo get_the_post_thumbnail( $post, 'large', array( 'class' => 'etm-photo', 'loading' => 'lazy' ) );
					} else {
						echo '<div class="etm-photo etm-photo--placeholder" aria-hidden="true">' . self::icon( 'user' ) . '</div>'; // phpcs:ignore
					}
					$hover_id = absint( ETM_Meta::get( $id, 'hover_image_id' ) );
					if ( $hover_id && 'swap' === $a['hover'] ) {
						echo wp_get_attachment_image( $hover_id, 'large', false, array( 'class' => 'etm-photo etm-photo--hover', 'loading' => 'lazy', 'aria-hidden' => 'true' ) );
					}
					?>
					<?php if ( 'overlay' === $a['style'] ) : ?>
						<div class="etm-overlay">
							<h3 class="etm-name"><?php echo self::name_link( $name, $hit_url, $a, $external ); // phpcs:ignore ?></h3>
							<?php if ( $job_title && 'yes' === $a['show_title'] ) : ?><p class="etm-role"><?php echo esc_html( $job_title ); ?></p><?php endif; ?>
							<?php if ( 'yes' === $a['show_social'] && $socials ) : ?><?php self::social_row( $socials ); ?><?php endif; ?>
						</div>
					<?php elseif ( 'hover' === $a['show_social'] && $socials ) : ?>
						<div class="etm-social-veil"><?php self::social_row( $socials ); ?></div>
					<?php endif; ?>
					<?php if ( $clickable ) : ?>
						<?php if ( $is_modal ) : ?>
							<button type="button" class="etm-hit" data-etm-open aria-haspopup="dialog">
								<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: member name */ __( 'View profile of %s', 'emotio-team' ), $name ) ); ?></span>
							</button>
						<?php else : ?>
							<a class="etm-hit" href="<?php echo esc_url( $hit_url ); ?>" <?php echo $external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
								<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: member name */ __( 'View profile of %s', 'emotio-team' ), $name ) ); ?></span>
							</a>
						<?php endif; ?>
					<?php endif; ?>
				</div>
				<?php if ( 'overlay' !== $a['style'] ) : ?>
					<div class="etm-body">
						<h3 class="etm-name"><?php echo self::name_link( $name, $hit_url, $a, $external ); // phpcs:ignore ?></h3>
						<?php if ( $job_title && 'yes' === $a['show_title'] ) : ?><p class="etm-role"><?php echo esc_html( $job_title ); ?></p><?php endif; ?>
						<?php if ( 'yes' === $a['show_bio'] && $excerpt ) : ?><p class="etm-bio"><?php echo esc_html( wp_trim_words( $excerpt, 24 ) ); ?></p><?php endif; ?>
						<?php self::contact_rows( $id, $a, $dept_names ); ?>
						<?php if ( 'yes' === $a['show_social'] && $socials ) : ?><?php self::social_row( $socials ); ?><?php endif; ?>
					</div>
				<?php endif; ?>
			</article>
			<?php if ( $is_modal ) : ?>
				<template class="etm-detail"><?php self::detail( $post, $socials ); ?></template>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Name markup — links out in "page" / "custom" modes
	 * (in modal/panel mode the whole card is the trigger).
	 */
	protected static function name_link( $name, $url, $a, $external = false ) {
		if ( in_array( $a['link'], array( 'page', 'custom' ), true ) ) {
			$target = $external ? ' target="_blank" rel="noopener noreferrer"' : '';
			return '<a href="' . esc_url( $url ) . '"' . $target . '>' . esc_html( $name ) . '</a>';
		}
		return esc_html( $name );
	}

	/**
	 * Optional on-card contact details (department, email, phone, location).
	 */
	protected static function contact_rows( $post_id, $a, $dept_names = array() ) {
		$rows = array();

		if ( 'yes' === $a['show_department'] && $dept_names ) {
			$rows[] = '<li class="etm-contact-dept">' . esc_html( implode( ' · ', $dept_names ) ) . '</li>';
		}
		if ( 'yes' === $a['show_email'] ) {
			$email = ETM_Meta::get( $post_id, 'email' );
			if ( $email ) {
				$rows[] = '<li>' . self::icon( 'email' ) . '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></li>';
			}
		}
		if ( 'yes' === $a['show_phone'] ) {
			foreach ( array( 'phone', 'mobile' ) as $field ) {
				$number = ETM_Meta::get( $post_id, $field );
				if ( $number ) {
					$rows[] = '<li>' . self::icon( 'phone' ) . '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $number ) ) . '">' . esc_html( $number ) . '</a></li>';
				}
			}
		}
		if ( 'yes' === $a['show_location'] ) {
			$location = ETM_Meta::get( $post_id, 'location' );
			if ( $location ) {
				$rows[] = '<li>' . self::icon( 'pin' ) . '<span>' . esc_html( $location ) . '</span></li>';
			}
		}

		if ( $rows ) {
			echo '<ul class="etm-contact">' . implode( '', $rows ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Social icon row.
	 */
	protected static function social_row( $socials ) {
		echo '<ul class="etm-socials">';
		foreach ( $socials as $key => $social ) {
			printf(
				'<li><a href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s">%3$s</a></li>',
				esc_url( $social['url'] ),
				esc_attr( $social['label'] ),
				self::icon( $key ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</ul>';
	}

	/**
	 * Full profile content used inside the modal / slide-out panel.
	 * Which sections render is controlled under Team → Settings
	 * ("Profile modal / slide-out content") and the etm_modal_sections
	 * filter. Social icons always sit at the very bottom.
	 */
	protected static function detail( $post, $socials ) {
		$id       = $post->ID;
		$sections = ETM_Settings::modal_sections( $post );
		$on       = array_fill_keys( $sections, true );

		$job_title = ETM_Meta::get( $id, 'job_title' );
		$email     = ETM_Meta::get( $id, 'email' );
		$phone     = ETM_Meta::get( $id, 'phone' );
		$mobile    = ETM_Meta::get( $id, 'mobile' );
		$location  = ETM_Meta::get( $id, 'location' );
		$pronouns  = ETM_Meta::get( $id, 'pronouns' );
		$fun_fact  = ETM_Meta::get( $id, 'fun_fact' );

		// Biography: run shortcodes so builder-authored content (WPBakery
		// rows, Salient elements) renders as text instead of raw tags,
		// then fall back to the excerpt.
		$bio = '';
		if ( trim( (string) $post->post_content ) ) {
			$bio = wp_kses_post( do_shortcode( shortcode_unautop( wpautop( $post->post_content ) ) ) );
		}
		if ( ! trim( wp_strip_all_tags( $bio ) ) && has_excerpt( $post ) ) {
			$bio = '<p>' . esc_html( get_the_excerpt( $post ) ) . '</p>';
		}

		// A hand-built <img> with a plain src: lazy-load plugins rewrite
		// generated thumbnail markup to data-src, which never resolves
		// inside a cloned <template>.
		$photo_url = get_the_post_thumbnail_url( $post, 'large' );
		?>
		<div class="etm-detail-inner">
			<?php if ( ! empty( $on['photo'] ) && $photo_url ) : ?>
				<div class="etm-detail-media">
					<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( get_the_title( $post ) ); ?>">
				</div>
			<?php endif; ?>
			<div class="etm-detail-body">
				<h2 class="etm-detail-name"><?php echo esc_html( get_the_title( $post ) ); ?><?php if ( ! empty( $on['pronouns'] ) && $pronouns ) : ?> <span class="etm-pronouns"><?php echo esc_html( $pronouns ); ?></span><?php endif; ?></h2>
				<?php if ( $job_title ) : ?><p class="etm-detail-role"><?php echo esc_html( $job_title ); ?></p><?php endif; ?>
				<?php if ( ! empty( $on['location'] ) && $location ) : ?><p class="etm-detail-meta"><?php echo self::icon( 'pin' ); // phpcs:ignore ?> <?php echo esc_html( $location ); ?></p><?php endif; ?>
				<?php if ( ! empty( $on['bio'] ) && $bio ) : ?><div class="etm-detail-bio"><?php echo $bio; // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
				<?php if ( ! empty( $on['custom_fields'] ) ) { self::custom_fields_list( $id ); } ?>
				<?php if ( ! empty( $on['fun_fact'] ) && $fun_fact ) : ?><p class="etm-fun-fact"><strong><?php esc_html_e( 'Fun fact:', 'emotio-team' ); ?></strong> <?php echo esc_html( $fun_fact ); ?></p><?php endif; ?>
				<?php if ( ! empty( $on['contact'] ) || ! empty( $on['vcard'] ) || ! empty( $on['profile_link'] ) ) : ?>
					<div class="etm-detail-actions">
						<?php if ( ! empty( $on['contact'] ) ) : ?>
							<?php if ( $email ) : ?>
								<a class="etm-btn" href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo self::icon( 'email' ); // phpcs:ignore ?><?php esc_html_e( 'Email', 'emotio-team' ); ?></a>
							<?php endif; ?>
							<?php if ( $phone ) : ?>
								<a class="etm-btn" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo self::icon( 'phone' ); // phpcs:ignore ?><?php echo esc_html( $phone ); ?></a>
							<?php endif; ?>
							<?php if ( $mobile ) : ?>
								<a class="etm-btn" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $mobile ) ); ?>"><?php echo self::icon( 'phone' ); // phpcs:ignore ?><?php echo esc_html( $mobile ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( ! empty( $on['vcard'] ) ) : ?>
							<a class="etm-btn etm-btn--ghost" href="<?php echo esc_url( ETM_Single::vcard_url( $id ) ); ?>"><?php echo self::icon( 'download' ); // phpcs:ignore ?><?php esc_html_e( 'Save contact', 'emotio-team' ); ?></a>
						<?php endif; ?>
						<?php if ( ! empty( $on['profile_link'] ) && ETM_Settings::get( 'enable_single' ) ) : ?>
							<a class="etm-btn etm-btn--ghost" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php esc_html_e( 'Full profile', 'emotio-team' ); ?></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $on['socials'] ) && $socials ) : ?><?php self::social_row( $socials ); ?><?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Definition list of a member's custom profile fields.
	 * Values that look like URLs or email addresses become links.
	 */
	public static function custom_fields_list( $post_id ) {
		$values = ETM_Meta::custom_values( $post_id );
		if ( ! $values ) {
			return;
		}
		echo '<dl class="etm-cf">';
		foreach ( $values as $field ) {
			echo '<dt>' . esc_html( $field['label'] ) . '</dt>';
			$value = $field['value'];
			if ( preg_match( '#^https?://#i', $value ) ) {
				$display = preg_replace( '#^https?://(www\.)?#i', '', untrailingslashit( $value ) );
				echo '<dd><a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $display ) . '</a></dd>';
			} elseif ( is_email( $value ) ) {
				echo '<dd><a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a></dd>';
			} else {
				echo '<dd>' . esc_html( $value ) . '</dd>';
			}
		}
		echo '</dl>';
	}

	/**
	 * schema.org ItemList of Person entries.
	 */
	protected static function schema( $people ) {
		$items = array();
		$seen  = array();
		// Grouped layouts can render a member more than once — dedupe.
		$people = array_values(
			array_filter(
				$people,
				function ( $p ) use ( &$seen ) {
					$sig = $p['name'] . '|' . $p['title'];
					if ( isset( $seen[ $sig ] ) ) {
						return false;
					}
					$seen[ $sig ] = true;
					return true;
				}
			)
		);
		foreach ( $people as $i => $p ) {
			$person = array_filter(
				array(
					'@type'    => 'Person',
					'name'     => $p['name'],
					'jobTitle' => $p['title'],
					'url'      => $p['url'],
					'image'    => $p['image'],
					'email'    => $p['email'] ? 'mailto:' . $p['email'] : '',
					'worksFor' => array(
						'@type' => 'Organization',
						'name'  => get_bloginfo( 'name' ),
						'url'   => home_url( '/' ),
					),
				)
			);
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'item'     => $person,
			);
		}
		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'itemListElement' => $items,
		);
		return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>';
	}

	/**
	 * Inline SVG icon set. Filterable via `etm_icon`.
	 */
	public static function icon( $name ) {
		$stroke = 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
		$icons  = array(
			'search'        => '<circle cx="11" cy="11" r="7" ' . $stroke . '/><line x1="21" y1="21" x2="16.65" y2="16.65" ' . $stroke . '/>',
			'close'         => '<line x1="18" y1="6" x2="6" y2="18" ' . $stroke . '/><line x1="6" y1="6" x2="18" y2="18" ' . $stroke . '/>',
			'chevron-left'  => '<polyline points="15 18 9 12 15 6" ' . $stroke . '/>',
			'chevron-right' => '<polyline points="9 18 15 12 9 6" ' . $stroke . '/>',
			'user'          => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" ' . $stroke . '/><circle cx="12" cy="7" r="4" ' . $stroke . '/>',
			'email'         => '<rect x="2" y="4" width="20" height="16" rx="2" ' . $stroke . '/><polyline points="22,6 12,13 2,6" ' . $stroke . '/>',
			'phone'         => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" ' . $stroke . '/>',
			'pin'           => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" ' . $stroke . '/><circle cx="12" cy="10" r="3" ' . $stroke . '/>',
			'download'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" ' . $stroke . '/><polyline points="7 10 12 15 17 10" ' . $stroke . '/><line x1="12" y1="3" x2="12" y2="15" ' . $stroke . '/>',
			'website'       => '<circle cx="12" cy="12" r="10" ' . $stroke . '/><line x1="2" y1="12" x2="22" y2="12" ' . $stroke . '/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" ' . $stroke . '/>',
			'linkedin'      => '<path fill="currentColor" d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.225 0z"/>',
			'twitter'       => '<path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>',
			'instagram'     => '<rect x="2" y="2" width="20" height="20" rx="5" ' . $stroke . '/><circle cx="12" cy="12" r="4.5" ' . $stroke . '/><circle cx="17.5" cy="6.5" r="1.2" fill="currentColor"/>',
			'facebook'      => '<path fill="currentColor" d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>',
			'youtube'       => '<path fill="currentColor" d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>',
			'github'        => '<path fill="currentColor" d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/>',
			'dribbble'      => '<circle cx="12" cy="12" r="10" ' . $stroke . '/><path d="M8.56 2.75c4.37 5.03 6.3 9.72 8.03 17.72" ' . $stroke . '/><path d="M2.5 10.5c5.5 1.5 12.5 1 19-3.5" ' . $stroke . '/><path d="M4.5 19c3-5 8.5-8 17-6.5" ' . $stroke . '/>',
			'behance'       => '<path fill="currentColor" d="M8.84 11.03c.98-.47 1.5-1.25 1.5-2.4 0-2.27-1.7-2.88-3.66-2.88H1v12.34h5.84c2.2 0 4.26-1.05 4.26-3.5 0-1.52-.72-2.64-2.26-3.05v-.5zM3.72 7.86h2.48c.95 0 1.81.27 1.81 1.38 0 1.02-.67 1.43-1.62 1.43H3.72V7.86zm2.83 8.13H3.72v-3.32h2.9c1.17 0 1.91.49 1.91 1.73 0 1.22-.88 1.59-1.98 1.59zM21.44 8.51h-5.09v1.24h5.09V8.51zM18.9 9.99c-2.79 0-4.68 2.1-4.68 4.87 0 2.87 1.79 4.83 4.68 4.83 2.19 0 3.61-.99 4.29-3.08h-2.23c-.24.79-1.02 1.2-1.99 1.2-1.35 0-2.19-.79-2.28-2.29h6.64c.14-3-1.44-5.53-4.43-5.53zm-2.2 3.94c.15-1.23.9-2.06 2.2-2.06 1.36 0 1.99.9 2.11 2.06h-4.31z"/>',
			'tiktok'        => '<path fill="currentColor" d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>',
		);

		$svg = isset( $icons[ $name ] ) ? $icons[ $name ] : $icons['website'];
		$svg = '<svg class="etm-icon etm-icon--' . esc_attr( $name ) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">' . $svg . '</svg>';

		return apply_filters( 'etm_icon', $svg, $name );
	}
}
