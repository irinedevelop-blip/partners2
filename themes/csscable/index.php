<?php
/**
 * Главный шаблон.
 *
 * @package CSSCable
 */

get_header();
?>

<div class="cc-content">
	<?php if ( have_posts() ) : ?>
		<div class="cc-page-grid">
			<?php while ( have_posts() ) : ?>
				<?php the_post(); ?>

				<article <?php post_class( 'cc-card cc-article-card' ); ?>>
					<h2 class="cc-article-card__title">
						<a href="<?php the_permalink(); ?>">
							<?php the_title(); ?>
						</a>
					</h2>

					<div class="cc-entry-content">
						<?php the_excerpt(); ?>
					</div>
				</article>
			<?php endwhile; ?>
		</div>

		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<div class="cc-card">
			<h1><?php esc_html_e( 'Ничего не найдено', 'csscable' ); ?></h1>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();
