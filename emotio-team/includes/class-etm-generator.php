<?php
/**
 * Shortcode Generator + Saved Displays.
 *
 * A visual admin screen that builds [emotio_team …] shortcodes live as
 * options are picked, and can save a configuration as a named, reusable
 * display: place [emotio_team_display id="3"] on any number of pages and
 * edit the configuration in ONE place forever after.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Generator {

	const OPTION = 'etm_displays';

	public static function init() {
		add_shortcode( 'emotio_team_display', array( __CLASS__, 'render_display' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_etm_save_display', array( __CLASS__, 'save_display' ) );
		add_action( 'admin_action_etm_delete_display', array( __CLASS__, 'delete_display' ) );
	}

	/* ------------------------------------------------------------ store */

	public static function displays() {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'next'  => 1,
				'items' => array(),
			)
		);
	}

	public static function get_display( $id ) {
		$displays = self::displays();
		return isset( $displays['items'][ $id ] ) ? $displays['items'][ $id ] : null;
	}

	/**
	 * [emotio_team_display id="3"]
	 */
	public static function render_display( $atts ) {
		$id      = isset( $atts['id'] ) ? absint( $atts['id'] ) : 0;
		$display = $id ? self::get_display( $id ) : null;
		if ( ! $display ) {
			return current_user_can( 'edit_posts' )
				? '<div class="etm etm--empty">' . esc_html__( 'Saved team display not found — check the id.', 'emotio-team' ) . '</div>'
				: '';
		}
		return ETM_Shortcode::render( $display['atts'] );
	}

	/**
	 * Keep only known attributes, lightly sanitized (render() re-validates
	 * everything against whitelists anyway).
	 */
	protected static function sanitize_atts( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$defaults = ETM_Shortcode::defaults();
		foreach ( $defaults as $key => $default ) {
			if ( ! isset( $raw[ $key ] ) || '' === trim( (string) $raw[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $raw[ $key ] );
			if ( (string) $default !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	public static function save_display() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'emotio-team' ) );
		}
		check_admin_referer( 'etm_save_display' );

		$displays = self::displays();
		$id       = isset( $_POST['display_id'] ) ? absint( $_POST['display_id'] ) : 0;
		$name     = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$atts     = self::sanitize_atts( isset( $_POST['etmg'] ) ? wp_unslash( $_POST['etmg'] ) : array() );

		if ( ! $name ) {
			$name = sprintf( /* translators: %d: display id */ __( 'Team display %d', 'emotio-team' ), $id ? $id : $displays['next'] );
		}
		if ( ! $id || ! isset( $displays['items'][ $id ] ) ) {
			$id = $displays['next'];
			$displays['next']++;
		}
		$displays['items'][ $id ] = array(
			'name' => $name,
			'atts' => $atts,
		);
		update_option( self::OPTION, $displays, false );

		wp_safe_redirect( admin_url( 'edit.php?post_type=team_member&page=etm-generator&saved=' . $id ) );
		exit;
	}

	public static function delete_display() {
		$id = isset( $_GET['display'] ) ? absint( $_GET['display'] ) : 0;
		check_admin_referer( 'etm_delete_display_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'emotio-team' ) );
		}
		$displays = self::displays();
		unset( $displays['items'][ $id ] );
		update_option( self::OPTION, $displays, false );
		wp_safe_redirect( admin_url( 'edit.php?post_type=team_member&page=etm-generator&deleted=1' ) );
		exit;
	}

	/* --------------------------------------------------------------- UI */

	public static function add_page() {
		add_submenu_page(
			'edit.php?post_type=team_member',
			__( 'Shortcode Generator', 'emotio-team' ),
			__( 'Shortcode Generator', 'emotio-team' ),
			'manage_options',
			'etm-generator',
			array( __CLASS__, 'render_page' )
		);
	}

	protected static function field( $key, $label, $control, $atts ) {
		$value = isset( $atts[ $key ] ) ? $atts[ $key ] : '';
		echo '<p class="etm-gen-field"><label for="etmg-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
		if ( is_array( $control ) ) {
			echo '<select id="etmg-' . esc_attr( $key ) . '" name="etmg[' . esc_attr( $key ) . ']">';
			foreach ( $control as $option_value => $option_label ) {
				echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( (string) $value, (string) $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'color' === $control ) {
			echo '<input type="text" class="code" style="width:110px" placeholder="' . esc_attr__( 'inherit', 'emotio-team' ) . '" id="etmg-' . esc_attr( $key ) . '" name="etmg[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		} else {
			echo '<input type="text" class="regular-text" id="etmg-' . esc_attr( $key ) . '" name="etmg[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $control ) . '">';
		}
		echo '</p>';
	}

	public static function render_page() {
		$displays = self::displays();
		$editing  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit     = $editing ? self::get_display( $editing ) : null;
		$atts     = $edit ? wp_parse_args( $edit['atts'], ETM_Shortcode::defaults() ) : ETM_Shortcode::defaults();
		$saved    = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$yesno  = array( 'no' => __( 'No', 'emotio-team' ), 'yes' => __( 'Yes', 'emotio-team' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Shortcode Generator & Saved Displays', 'emotio-team' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php esc_html_e( 'Display saved. Place it anywhere with:', 'emotio-team' ); ?>
					<code>[emotio_team_display id="<?php echo esc_html( $saved ); ?>"]</code>
				</p></div>
			<?php elseif ( ! empty( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Display deleted.', 'emotio-team' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="etm-generator">
				<?php wp_nonce_field( 'etm_save_display' ); ?>
				<input type="hidden" name="action" value="etm_save_display">
				<input type="hidden" name="display_id" value="<?php echo esc_attr( $editing ); ?>">

				<div class="card" style="max-width:1100px;">
					<h2 style="margin-top:0;"><?php echo $edit ? esc_html( sprintf( /* translators: %s: display name */ __( 'Editing: %s', 'emotio-team' ), $edit['name'] ) ) : esc_html__( 'Build a team display', 'emotio-team' ); ?></h2>

					<div class="etm-gen-grid">
						<fieldset>
							<legend><strong><?php esc_html_e( 'Layout', 'emotio-team' ); ?></strong></legend>
							<?php
							self::field( 'layout', __( 'Layout', 'emotio-team' ), array( 'grid' => __( 'Grid', 'emotio-team' ), 'slider' => __( 'Slider', 'emotio-team' ), 'list' => __( 'List', 'emotio-team' ), 'spotlight' => __( 'Spotlight', 'emotio-team' ) ), $atts );
							self::field( 'slider_style', __( 'Slider style', 'emotio-team' ), array( 'drag' => __( 'Drag / swipe', 'emotio-team' ), 'paged' => __( 'Paged with dots', 'emotio-team' ) ), $atts );
							self::field( 'group_by', __( 'Group by department', 'emotio-team' ), array( '' => __( 'No', 'emotio-team' ), 'department' => __( 'Yes', 'emotio-team' ) ), $atts );
							self::field( 'columns', __( 'Columns (desktop)', 'emotio-team' ), array( '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6' ), $atts );
							self::field( 'columns_tablet', __( 'Columns (tablet)', 'emotio-team' ), array( '' => __( 'Auto', 'emotio-team' ), '1' => '1', '2' => '2', '3' => '3', '4' => '4' ), $atts );
							self::field( 'columns_mobile', __( 'Columns (mobile)', 'emotio-team' ), array( '' => __( 'Auto', 'emotio-team' ), '1' => '1', '2' => '2', '3' => '3' ), $atts );
							?>
						</fieldset>
						<fieldset>
							<legend><strong><?php esc_html_e( 'Who to show', 'emotio-team' ); ?></strong></legend>
							<?php
							self::field( 'department', __( 'Department slugs', 'emotio-team' ), 'design,management', $atts );
							self::field( 'relation', __( 'Multiple departments match', 'emotio-team' ), array( 'OR' => __( 'ANY of them (OR)', 'emotio-team' ), 'AND' => __( 'ALL of them (AND)', 'emotio-team' ) ), $atts );
							self::field( 'tag', __( 'Skill / tag slugs', 'emotio-team' ), '', $atts );
							self::field( 'ids', __( 'Include only these IDs', 'emotio-team' ), '12,18,24', $atts );
							self::field( 'exclude', __( 'Exclude these IDs', 'emotio-team' ), '15,22', $atts );
							self::field( 'featured', __( 'Featured members only', 'emotio-team' ), $yesno, $atts );
							self::field( 'limit', __( 'Limit (-1 = all)', 'emotio-team' ), '-1', $atts );
							self::field( 'orderby', __( 'Order by', 'emotio-team' ), array( 'menu_order' => __( 'Custom order', 'emotio-team' ), 'title' => __( 'Name', 'emotio-team' ), 'job_title' => __( 'Job title', 'emotio-team' ), 'date' => __( 'Date created', 'emotio-team' ), 'id' => __( 'ID', 'emotio-team' ), 'rand' => __( 'Random', 'emotio-team' ) ), $atts );
							self::field( 'order', __( 'Direction', 'emotio-team' ), array( 'ASC' => 'ASC', 'DESC' => 'DESC' ), $atts );
							?>
						</fieldset>
						<fieldset>
							<legend><strong><?php esc_html_e( 'Fields & behaviour', 'emotio-team' ); ?></strong></legend>
							<?php
							self::field( 'link', __( 'Card click', 'emotio-team' ), array( 'modal' => __( 'Profile modal', 'emotio-team' ), 'panel' => __( 'Slide-out panel', 'emotio-team' ), 'page' => __( 'Profile page', 'emotio-team' ), 'custom' => __( 'Custom URL', 'emotio-team' ), 'none' => __( 'Not clickable', 'emotio-team' ) ), $atts );
							self::field( 'show_title', __( 'Show job title', 'emotio-team' ), array( 'yes' => __( 'Yes', 'emotio-team' ), 'no' => __( 'No', 'emotio-team' ) ), $atts );
							self::field( 'show_bio', __( 'Show short bio', 'emotio-team' ), $yesno, $atts );
							self::field( 'show_department', __( 'Show department on card', 'emotio-team' ), $yesno, $atts );
							self::field( 'show_email', __( 'Show email on card', 'emotio-team' ), $yesno, $atts );
							self::field( 'show_phone', __( 'Show phone on card', 'emotio-team' ), $yesno, $atts );
							self::field( 'show_location', __( 'Show location on card', 'emotio-team' ), $yesno, $atts );
							self::field( 'show_social', __( 'Social icons', 'emotio-team' ), array( 'yes' => __( 'Show on card', 'emotio-team' ), 'hover' => __( 'Reveal on hover', 'emotio-team' ), 'no' => __( 'Hide', 'emotio-team' ) ), $atts );
							self::field( 'show_filter', __( 'Department filter chips', 'emotio-team' ), $yesno, $atts );
							self::field( 'show_search', __( 'Live search box', 'emotio-team' ), $yesno, $atts );
							?>
						</fieldset>
						<fieldset>
							<legend><strong><?php esc_html_e( 'Design', 'emotio-team' ); ?></strong></legend>
							<?php
							self::field( 'style', __( 'Card style', 'emotio-team' ), array( 'cards' => __( 'Cards', 'emotio-team' ), 'minimal' => __( 'Minimal', 'emotio-team' ), 'overlay' => __( 'Image overlay', 'emotio-team' ), 'circle' => __( 'Circle portrait', 'emotio-team' ) ), $atts );
							self::field( 'hover', __( 'Hover effect', 'emotio-team' ), array( 'lift' => __( 'Lift', 'emotio-team' ), 'zoom' => __( 'Zoom', 'emotio-team' ), 'swap' => __( 'Swap photo', 'emotio-team' ), 'grayscale' => __( 'Grayscale', 'emotio-team' ), 'none' => __( 'None', 'emotio-team' ) ), $atts );
							self::field( 'image_ratio', __( 'Photo ratio', 'emotio-team' ), array( '3-4' => '3:4', '1-1' => '1:1', '2-3' => '2:3', '4-3' => '4:3', '16-9' => '16:9' ), $atts );
							self::field( 'spacing', __( 'Element spacing', 'emotio-team' ), array( 'tight' => __( 'Tight', 'emotio-team' ), 'normal' => __( 'Normal', 'emotio-team' ), 'spaced' => __( 'Spaced', 'emotio-team' ) ), $atts );
							self::field( 'accent', __( 'Accent colour (hex)', 'emotio-team' ), 'color', $atts );
							self::field( 'gap', __( 'Grid gap (px)', 'emotio-team' ), '', $atts );
							self::field( 'autoplay', __( 'Slider autoplay', 'emotio-team' ), $yesno, $atts );
							?>
						</fieldset>
					</div>

					<h3><?php esc_html_e( 'Your shortcode', 'emotio-team' ); ?></h3>
					<p><textarea id="etm-gen-output" class="large-text code" rows="2" readonly onclick="this.select()"></textarea></p>
					<p>
						<button type="button" class="button" id="etm-gen-copy"><?php esc_html_e( 'Copy shortcode', 'emotio-team' ); ?></button>
						<span style="margin:0 12px;color:#999;">|</span>
						<label><strong><?php esc_html_e( 'Save as reusable display:', 'emotio-team' ); ?></strong>
							<input type="text" name="display_name" placeholder="<?php esc_attr_e( 'e.g. Architecture Team', 'emotio-team' ); ?>" value="<?php echo esc_attr( $edit['name'] ?? '' ); ?>">
						</label>
						<button type="submit" class="button button-primary"><?php echo $edit ? esc_html__( 'Update display', 'emotio-team' ) : esc_html__( 'Save display', 'emotio-team' ); ?></button>
						<?php if ( $edit ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=team_member&page=etm-generator' ) ); ?>"><?php esc_html_e( 'Cancel editing', 'emotio-team' ); ?></a>
						<?php endif; ?>
					</p>
					<p class="description"><?php esc_html_e( 'Saved displays give you a tiny shortcode like [emotio_team_display id="3"]. Use it on any number of pages — change the saved configuration once and every page updates.', 'emotio-team' ); ?></p>
				</div>
			</form>

			<?php if ( $displays['items'] ) : ?>
				<h2><?php esc_html_e( 'Saved displays', 'emotio-team' ); ?></h2>
				<table class="widefat striped" style="max-width:1100px;">
					<thead><tr>
						<th><?php esc_html_e( 'Name', 'emotio-team' ); ?></th>
						<th><?php esc_html_e( 'Shortcode', 'emotio-team' ); ?></th>
						<th><?php esc_html_e( 'Summary', 'emotio-team' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $displays['items'] as $id => $display ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $display['name'] ); ?></strong></td>
							<td><code>[emotio_team_display id="<?php echo esc_html( $id ); ?>"]</code></td>
							<td><small><?php
								$summary = array();
								foreach ( $display['atts'] as $att_key => $att_value ) {
									$summary[] = $att_key . '=' . $att_value;
								}
								echo esc_html( $summary ? implode( ', ', array_slice( $summary, 0, 8 ) ) . ( count( $summary ) > 8 ? '…' : '' ) : __( 'All defaults', 'emotio-team' ) );
							?></small></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'edit.php?post_type=team_member&page=etm-generator&edit=' . $id ) ); ?>"><?php esc_html_e( 'Edit', 'emotio-team' ); ?></a>
								<a class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Delete this display? Pages using it will show nothing.', 'emotio-team' ); ?>')"
									href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=etm_delete_display&display=' . $id ), 'etm_delete_display_' . $id ) ); ?>"><?php esc_html_e( 'Delete', 'emotio-team' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<style>
			.etm-gen-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px 28px;}
			.etm-gen-grid fieldset{border:0;padding:0;margin:0;}
			.etm-gen-grid legend{padding:6px 0;font-size:14px;}
			.etm-gen-field{margin:6px 0;}
			.etm-gen-field input.regular-text{width:100%;max-width:220px;}
		</style>
		<script>
		(function(){
			var form = document.getElementById('etm-generator');
			var output = document.getElementById('etm-gen-output');
			var defaults = <?php echo wp_json_encode( array_map( 'strval', ETM_Shortcode::defaults() ) ); ?>;

			function build(){
				var parts = ['emotio_team'];
				form.querySelectorAll('[name^="etmg["]').forEach(function(field){
					var key = field.name.slice(5, -1);
					var value = (field.value || '').trim();
					if (value === '' || value === (defaults[key] || '')) { return; }
					parts.push(key + '="' + value.replace(/"/g, '') + '"');
				});
				output.value = '[' + parts.join(' ') + ']';
			}
			form.addEventListener('input', build);
			form.addEventListener('change', build);
			build();

			document.getElementById('etm-gen-copy').addEventListener('click', function(){
				output.select();
				try {
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(output.value);
					} else {
						document.execCommand('copy');
					}
					this.textContent = '<?php echo esc_js( __( 'Copied!', 'emotio-team' ) ); ?>';
					var btn = this;
					setTimeout(function(){ btn.textContent = '<?php echo esc_js( __( 'Copy shortcode', 'emotio-team' ) ); ?>'; }, 1500);
				} catch (e) {}
			});
		})();
		</script>
		<?php
	}
}
