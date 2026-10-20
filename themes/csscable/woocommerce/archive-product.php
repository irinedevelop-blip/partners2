<?php
/**
 * Каталог товаров.
 *
 * @package CSSCable
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

do_action( 'woocommerce_before_main_content' );
?>

<section class="cc-catalog">
	<header class="cc-catalog-header">
		<div>
			<h1 class="cc-catalog-header__title">
				<?php
				if ( is_product_category() || is_product_tag() ) {
					woocommerce_page_title();
				} else {
					esc_html_e( 'Каталог продукции', 'csscable' );
				}
				?>
			</h1>

			<div class="cc-catalog-header__subtitle">
				<?php echo esc_html( csscable_product_count_text() ); ?>

				<?php if ( is_user_logged_in() ) : ?>
					<span class="cc-divider">·</span>
					<?php esc_html_e( 'показаны ваши персональные цены', 'csscable' ); ?>

					<?php
$contract_number = function_exists( 'csscable_b2b_contract' )
	? csscable_b2b_contract()
	: '';
?>

<?php if ( $contract_number ) : ?>
	<span class="cc-contract-badge">
		<?php
		echo esc_html(
			sprintf( 'Договор %s', $contract_number )
		);
		?>
	</span>
<?php endif; ?>

				<?php endif; ?>
			</div>
		</div>
	</header>

	<div class="cc-catalog-toolbar">
		<form
			class="cc-product-search"
			role="search"
			method="get"
			action="<?php echo esc_url( home_url( '/' ) ); ?>"
		>
			<span class="cc-product-search__icon">
				<?php csscable_icon( 'search' ); ?>
			</span>

			<input
				type="search"
				name="s"
				value="<?php echo esc_attr( get_search_query() ); ?>"
				placeholder="<?php esc_attr_e( 'Артикул, марка, сечение...', 'csscable' ); ?>"
			>

			<input type="hidden" name="post_type" value="product">
		</form>

		<div class="cc-category-chips">
			<a
				class="cc-category-chip <?php echo is_shop() ? 'is-active' : ''; ?>"
				href="<?php echo esc_url( csscable_shop_url() ); ?>"
			>
				<?php esc_html_e( 'Все', 'csscable' ); ?>
			</a>

			<?php
			$categories = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
				'number'     => 6,
			) );

			if ( ! is_wp_error( $categories ) ) :
				$current_term = get_queried_object_id();

				foreach ( $categories as $category ) :
					?>
					<a
						class="cc-category-chip <?php echo $current_term === $category->term_id ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( get_term_link( $category ) ); ?>"
					>
						<?php echo esc_html( $category->name ); ?>
					</a>
					<?php
				endforeach;
			endif;
			?>
		</div>

		<div class="cc-catalog-ordering">
			<?php woocommerce_catalog_ordering(); ?>
		</div>
	</div>

	<?php do_action( 'woocommerce_archive_description' ); ?>

	<?php if ( woocommerce_product_loop() ) : ?>
		<?php do_action( 'woocommerce_before_shop_loop' ); ?>

		<div class="cc-product-table">
			<div class="cc-product-table__head">
				<div><?php esc_html_e( 'SKU / Наименование', 'csscable' ); ?></div>
				<div><?php esc_html_e( 'Наличие', 'csscable' ); ?></div>
				<div class="cc-align-right"><?php esc_html_e( 'Моя цена', 'csscable' ); ?></div>
				<div></div>
			</div>

			<?php woocommerce_product_loop_start(); ?>

			<?php while ( have_posts() ) : ?>
				<?php
				the_post();

				do_action( 'woocommerce_shop_loop' );

				wc_get_template_part( 'content', 'product' );
				?>
			<?php endwhile; ?>

			<?php woocommerce_product_loop_end(); ?>
		</div>

		<?php do_action( 'woocommerce_after_shop_loop' ); ?>
	<?php else : ?>
		<div class="cc-card cc-empty-state">
			<div class="cc-empty-state__icon">
				<?php csscable_icon( 'search' ); ?>
			</div>

			<h2><?php esc_html_e( 'Товары не найдены', 'csscable' ); ?></h2>

			<p>
				<?php esc_html_e( 'Измените запрос или выберите другую категорию.', 'csscable' ); ?>
			</p>

			<a class="button" href="<?php echo esc_url( csscable_shop_url() ); ?>">
				<?php esc_html_e( 'Показать все товары', 'csscable' ); ?>
			</a>
		</div>
	<?php endif; ?>
</section>

<?php
do_action( 'woocommerce_after_main_content' );

get_footer( 'shop' );
