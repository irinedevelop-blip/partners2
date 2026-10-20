<?php
/**
 * Одна строка товара в каталоге.
 *
 * @package CSSCable
 */

defined( 'ABSPATH' ) || exit;

global $product;

if (
	! $product instanceof WC_Product
	|| ! $product->is_visible()
) {
	return;
}

$product_id = $product->get_id();
$sku        = $product->get_sku();
$stock      = $product->get_stock_quantity();
$product_unit = function_exists( 'csscable_b2b_product_unit' )
	? csscable_b2b_product_unit( $product )
	: 'м';

$categories = wc_get_product_category_list( $product_id, ' · ' );

$regular_price = (float) $product->get_regular_price();
$sale_price    = (float) $product->get_sale_price();
$discount      = 0;

if ( $regular_price > 0 && $sale_price > 0 && $sale_price < $regular_price ) {
	$discount = (int) round(
		( ( $regular_price - $sale_price ) / $regular_price ) * 100
	);
}
?>

<li <?php wc_product_class( 'cc-product-row', $product ); ?>>
	<div class="cc-product-row__product">
		<a
			class="cc-product-row__image"
			href="<?php the_permalink(); ?>"
			aria-label="<?php echo esc_attr( $product->get_name() ); ?>"
		>
			<?php if ( has_post_thumbnail() ) : ?>
				<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
			<?php else : ?>
				<svg viewBox="0 0 48 48" aria-hidden="true">
					<circle cx="24" cy="24" r="16" fill="none" stroke="currentColor" stroke-width="4"/>
					<circle cx="24" cy="24" r="5" fill="none" stroke="currentColor" stroke-width="3"/>
					<path d="M8 24h8M32 24h8" stroke="currentColor" stroke-width="3"/>
				</svg>
			<?php endif; ?>
		</a>

		<div class="cc-product-row__info">
			<a class="cc-product-row__title" href="<?php the_permalink(); ?>">
				<?php echo esc_html( $product->get_name() ); ?>
			</a>

			<div class="cc-product-row__meta">
				<?php if ( $sku ) : ?>
					<span class="cc-sku">
						SKU: <?php echo esc_html( $sku ); ?>
					</span>
				<?php endif; ?>

				<?php if ( $categories ) : ?>
					<span class="cc-product-row__categories">
						<?php echo wp_kses_post( $categories ); ?>
					</span>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="cc-product-row__stock">
		<?php if ( $product->is_in_stock() ) : ?>
			<span class="cc-badge cc-badge--success">
				<span class="cc-badge__dot"></span>

				<?php if ( null !== $stock ) : ?>
					<span class="cc-num">
						<?php echo esc_html( number_format_i18n( $stock ) ); ?>
					</span>
					<?php echo esc_html( $product_unit ); ?>
				<?php else : ?>
					<?php esc_html_e( 'В наличии', 'csscable' ); ?>
				<?php endif; ?>
			</span>
		<?php else : ?>
			<span class="cc-badge cc-badge--danger">
				<span class="cc-badge__dot"></span>
				<?php esc_html_e( 'Нет в наличии', 'csscable' ); ?>
			</span>
		<?php endif; ?>
	</div>

	<div class="cc-product-row__price">
		<?php if ( $discount > 0 ) : ?>
			<span class="cc-badge cc-badge--accent">
				−<?php echo esc_html( $discount ); ?>%
			</span>
		<?php endif; ?>

		<div class="cc-price">
			<?php echo wp_kses_post( $product->get_price_html() ); ?>
		</div>

		<?php
$product_unit = function_exists( 'csscable_b2b_product_unit' )
	? csscable_b2b_product_unit( $product )
	: 'м';
?>

<span class="cc-price-unit">
	<?php
	echo esc_html(
		sprintf( 'за %s', $product_unit )
	);
	?>
</span>

	</div>

	<div class="cc-product-row__actions">
		<a class="button cc-button-secondary" href="<?php the_permalink(); ?>">
			<?php esc_html_e( 'Открыть', 'csscable' ); ?>
		</a>

		<?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
			<?php woocommerce_template_loop_add_to_cart(); ?>
		<?php endif; ?>
	</div>
</li>
