<?php
/**
 * Шаблон страницы.
 *
 * @package CSSCable
 */

get_header();
?>

<div class="cc-content">
	<?php while ( have_posts() ) : ?>
		<?php the_post(); ?>

		<article <?php post_class( 'cc-page' ); ?>>
			<?php if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() && ! is_account_page() ) ) : ?>
				<header class="cc-page-header">
					<h1><?php the_title(); ?></h1>
				</header>
			<?php endif; ?>

			<div class="cc-entry-content">
				<?php the_content(); ?>
			</div>
		</article>
	<?php endwhile; ?>
</div>

<?php
get_footer();
