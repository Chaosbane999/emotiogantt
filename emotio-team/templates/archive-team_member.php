<?php
/**
 * Team archive — a searchable, filterable portfolio-style listing.
 * Override by copying to {your-theme}/emotio-team/archive-team_member.php
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$etm_title = is_tax() ? single_term_title( '', false ) : post_type_archive_title( '', false );
$etm_atts  = array(
	'layout'      => 'grid',
	'show_filter' => 'yes',
	'show_search' => 'yes',
	'show_bio'    => 'yes',
);
if ( is_tax( ETM_CPT::TAX_DEPT ) ) {
	$etm_atts['department'] = get_queried_object()->slug;
	$etm_atts['show_filter'] = 'no';
}
if ( is_tax( ETM_CPT::TAX_TAG ) ) {
	$etm_atts['tag'] = get_queried_object()->slug;
	$etm_atts['show_filter'] = 'no';
}
?>
<div class="container-wrap etm-archive-wrap">
	<div class="container main-content etm-archive">
		<header class="etm-archive-header">
			<h1 class="etm-archive-title"><?php echo esc_html( $etm_title ? $etm_title : __( 'Meet the Team', 'emotio-team' ) ); ?></h1>
			<?php if ( is_tax() && term_description() ) : ?>
				<div class="etm-archive-intro"><?php echo wp_kses_post( term_description() ); ?></div>
			<?php endif; ?>
		</header>
		<?php echo ETM_Shortcode::render( $etm_atts ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</div>
</div>
<?php
get_footer();
