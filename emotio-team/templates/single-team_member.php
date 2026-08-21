<?php
/**
 * Single team member profile.
 * Override by copying to {your-theme}/emotio-team/single-team_member.php
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	$etm_id     = get_the_ID();
	$job_title  = ETM_Meta::get( $etm_id, 'job_title' );
	$email      = ETM_Meta::get( $etm_id, 'email' );
	$phone      = ETM_Meta::get( $etm_id, 'phone' );
	$location   = ETM_Meta::get( $etm_id, 'location' );
	$pronouns   = ETM_Meta::get( $etm_id, 'pronouns' );
	$fun_fact   = ETM_Meta::get( $etm_id, 'fun_fact' );
	$socials    = ETM_Meta::socials( $etm_id );
	$dept_terms = get_the_terms( $etm_id, ETM_CPT::TAX_DEPT );

	ETM_Shortcode::enqueue_assets();
	?>
	<div class="container-wrap etm-single-wrap">
		<div class="container main-content etm etm-single">
			<a class="etm-back" href="<?php echo esc_url( get_post_type_archive_link( ETM_CPT::POST_TYPE ) ?: home_url( '/' ) ); ?>">
				<?php echo ETM_Shortcode::icon( 'chevron-left' ); // phpcs:ignore ?> <?php esc_html_e( 'Back to team', 'emotio-team' ); ?>
			</a>
			<article <?php post_class( 'etm-profile' ); ?>>
				<div class="etm-profile-media">
					<?php if ( has_post_thumbnail() ) : ?>
						<?php the_post_thumbnail( 'large', array( 'class' => 'etm-profile-photo' ) ); ?>
					<?php endif; ?>
				</div>
				<div class="etm-profile-body">
					<h1 class="etm-profile-name"><?php the_title(); ?><?php if ( $pronouns ) : ?> <span class="etm-pronouns"><?php echo esc_html( $pronouns ); ?></span><?php endif; ?></h1>
					<?php if ( $job_title ) : ?><p class="etm-profile-role"><?php echo esc_html( $job_title ); ?></p><?php endif; ?>

					<ul class="etm-profile-meta">
						<?php if ( $dept_terms && ! is_wp_error( $dept_terms ) ) : ?>
							<li><?php echo get_the_term_list( $etm_id, ETM_CPT::TAX_DEPT, '', ', ' ); // phpcs:ignore ?></li>
						<?php endif; ?>
						<?php if ( $location ) : ?><li><?php echo ETM_Shortcode::icon( 'pin' ); // phpcs:ignore ?> <?php echo esc_html( $location ); ?></li><?php endif; ?>
						<?php if ( $email ) : ?><li><?php echo ETM_Shortcode::icon( 'email' ); // phpcs:ignore ?> <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></li><?php endif; ?>
						<?php if ( $phone ) : ?><li><?php echo ETM_Shortcode::icon( 'phone' ); // phpcs:ignore ?> <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></li><?php endif; ?>
					</ul>

					<div class="etm-profile-bio"><?php the_content(); ?></div>

					<?php if ( $fun_fact ) : ?>
						<p class="etm-fun-fact"><strong><?php esc_html_e( 'Fun fact:', 'emotio-team' ); ?></strong> <?php echo esc_html( $fun_fact ); ?></p>
					<?php endif; ?>

					<div class="etm-detail-actions">
						<a class="etm-btn etm-btn--ghost" href="<?php echo esc_url( ETM_Single::vcard_url( $etm_id ) ); ?>"><?php echo ETM_Shortcode::icon( 'download' ); // phpcs:ignore ?><?php esc_html_e( 'Save contact', 'emotio-team' ); ?></a>
					</div>

					<?php if ( $socials ) : ?>
						<ul class="etm-socials">
							<?php foreach ( $socials as $etm_key => $social ) : ?>
								<li><a href="<?php echo esc_url( $social['url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $social['label'] ); ?>"><?php echo ETM_Shortcode::icon( $etm_key ); // phpcs:ignore ?></a></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</article>

			<?php
			// Related members from the same department.
			if ( $dept_terms && ! is_wp_error( $dept_terms ) ) {
				$related = ETM_Shortcode::render(
					array(
						'department'  => $dept_terms[0]->slug,
						'exclude'     => $etm_id,
						'limit'       => 4,
						'columns'     => 4,
						'show_social' => 'no',
					)
				);
				if ( false === strpos( $related, 'etm--empty' ) ) {
					echo '<div class="etm-related"><h2>' . esc_html__( 'Also in this team', 'emotio-team' ) . '</h2>' . $related . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
			}
			?>
		</div>
	</div>
	<?php
endwhile;

get_footer();
