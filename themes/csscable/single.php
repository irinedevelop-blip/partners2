<?php
/**
 * Шаблон записи.
 *
 * @package CSSCable
 */

get_header();
?>

<div class="cc-content">
	<?php while ( have_posts() ) : ?>
		<?php the_post(); ?>

		<article <?php post_class( 'cc-card cc-single-post' ); ?>>
			<header class="cc-page-header">
				<h1><?php the_title(); ?></h1>
			</header>

			<div class="cc-entry-content">
				<?php the_content(); ?>
			</div>
		</article>
	<?php endwhile; ?>
</div>

<?php
get_footer();
