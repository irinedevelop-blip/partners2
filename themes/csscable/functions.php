<?php
/**
 * Основные функции темы CSS Cable B2B.
 *
 * @package CSSCable
 */

defined( 'ABSPATH' ) || exit;

define( 'CSSCABLE_VERSION', '0.1.0' );
define( 'CSSCABLE_DIR', get_template_directory() );
define( 'CSSCABLE_URI', get_template_directory_uri() );

/**
 * Настройка темы.
 */
function csscable_setup(): void {
	load_theme_textdomain(
		'csscable',
		CSSCABLE_DIR . '/languages'
	);

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array(
		'height'      => 70,
		'width'       => 220,
		'flex-height' => true,
		'flex-width'  => true,
	) );

	add_theme_support( 'html5', array(
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
		'style',
		'script',
	) );

	add_theme_support( 'woocommerce', array(
		'thumbnail_image_width' => 420,
		'single_image_width'    => 720,
		'product_grid'          => array(
			'default_rows'    => 12,
			'min_rows'        => 4,
			'max_rows'        => 30,
			'default_columns' => 1,
			'min_columns'     => 1,
			'max_columns'     => 1,
		),
	) );

	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	register_nav_menus( array(
		'primary' => __( 'Основное меню', 'csscable' ),
		'footer'  => __( 'Меню в подвале', 'csscable' ),
	) );
}
add_action( 'after_setup_theme', 'csscable_setup' );

/**
 * Подключение стилей и скриптов.
 */
function csscable_enqueue_assets(): void {
	wp_enqueue_style(
		'csscable-fonts',
		'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'csscable-style',
		get_stylesheet_uri(),
		array(),
		CSSCABLE_VERSION
	);

	wp_enqueue_style(
		'csscable-app',
		CSSCABLE_URI . '/assets/css/app.css',
		array( 'csscable-style' ),
		CSSCABLE_VERSION
	);

	wp_enqueue_script(
		'csscable-theme',
		CSSCABLE_URI . '/assets/js/theme.js',
		array(),
		CSSCABLE_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'csscable_enqueue_assets' );

/**
 * Убираем стандартный сайдбар WooCommerce.
 */
function csscable_remove_woocommerce_sidebar(): void {
	remove_action(
		'woocommerce_sidebar',
		'woocommerce_get_sidebar',
		10
	);
}
add_action( 'wp', 'csscable_remove_woocommerce_sidebar' );

/**
 * Собственная обёртка WooCommerce.
 */
function csscable_woocommerce_wrappers(): void {
	remove_action(
		'woocommerce_before_main_content',
		'woocommerce_output_content_wrapper',
		10
	);

	remove_action(
		'woocommerce_after_main_content',
		'woocommerce_output_content_wrapper_end',
		10
	);

	add_action(
		'woocommerce_before_main_content',
		'csscable_wrapper_start',
		10
	);

	add_action(
		'woocommerce_after_main_content',
		'csscable_wrapper_end',
		10
	);
}
add_action( 'wp', 'csscable_woocommerce_wrappers' );

function csscable_wrapper_start(): void {
	echo '<div class="cc-content">';
}

function csscable_wrapper_end(): void {
	echo '</div>';
}

/**
 * Количество товаров в корзине.
 */
function csscable_cart_count(): int {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0;
	}

	return (int) WC()->cart->get_cart_contents_count();
}

/**
 * AJAX-обновление счётчика корзины.
 *
 * @param array $fragments WooCommerce fragments.
 * @return array
 */
function csscable_cart_fragments( array $fragments ): array {
	ob_start();
	?>
	<span class="cc-cart-count">
		<?php echo esc_html( csscable_cart_count() ); ?>
	</span>
	<?php

	$fragments['.cc-cart-count'] = ob_get_clean();

	return $fragments;
}
add_filter(
	'woocommerce_add_to_cart_fragments',
	'csscable_cart_fragments'
);

/**
 * URL страницы магазина.
 */
function csscable_shop_url(): string {
	if ( function_exists( 'wc_get_page_permalink' ) ) {
		return wc_get_page_permalink( 'shop' );
	}

	return home_url( '/' );
}

/**
 * URL корзины.
 */
function csscable_cart_url(): string {
	if ( function_exists( 'wc_get_cart_url' ) ) {
		return wc_get_cart_url();
	}

	return home_url( '/' );
}

/**
 * URL личного кабинета.
 */
function csscable_account_url(): string {
	if ( function_exists( 'wc_get_page_permalink' ) ) {
		return wc_get_page_permalink( 'myaccount' );
	}

	return wp_login_url();
}

/**
 * Активный пункт меню.
 *
 * @param string $section Раздел.
 */
function csscable_is_active( string $section ): bool {
	switch ( $section ) {
		case 'catalog':
			return function_exists( 'is_shop' )
				&& (
					is_shop()
					|| is_product()
					|| is_product_category()
					|| is_product_tag()
				);

		case 'cart':
			return function_exists( 'is_cart' ) && is_cart();

		case 'checkout':
			return function_exists( 'is_checkout' ) && is_checkout();

		case 'account':
			return function_exists( 'is_account_page' ) && is_account_page();

		default:
			return is_front_page();
	}
}

/**
 * Простая SVG-иконка.
 *
 * @param string $name Название иконки.
 */
function csscable_icon( string $name ): void {
	$icons = array(
		'home' => '<path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z"/>',
		'catalog' => '<path d="M4 5h16M4 12h16M4 19h16"/><path d="M7 3v4M7 10v4M7 17v4"/>',
		'cart' => '<circle cx="9" cy="20" r="1"/><circle cx="19" cy="20" r="1"/><path d="M3 4h2l2.4 10.4a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6"/>',
		'orders' => '<path d="M6 3h12v18H6z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
		'documents' => '<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v5h5M9 12h6M9 16h6"/>',
		'company' => '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M8 10h2M14 10h2"/>',
		'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
		'logout' => '<path d="M10 17l5-5-5-5M15 12H3M14 3h7v18h-7"/>',
	);

	if ( ! isset( $icons[ $name ] ) ) {
		return;
	}

	echo '<svg class="cc-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
	echo wp_kses(
		$icons[ $name ],
		array(
			'path'   => array( 'd' => true ),
			'circle' => array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			),
		)
	);
	echo '</svg>';
}

/**
 * Текст внизу каталога.
 */
function csscable_product_count_text(): string {
	if ( ! function_exists( 'wc_get_loop_prop' ) ) {
		return '';
	}

	$total = (int) wc_get_loop_prop( 'total' );

	return sprintf(
		_n(
			'%s товар',
			'%s товаров',
			$total,
			'csscable'
		),
		number_format_i18n( $total )
	);
}

/**
 * Дополнительные классы body.
 */
function csscable_body_classes( array $classes ): array {
	$classes[] = 'cc-theme';
	$classes[] = 'pro';

	if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
		$classes[] = 'cc-woocommerce-page';
	}

	return $classes;
}
add_filter( 'body_class', 'csscable_body_classes' );

/**
 * Не показываем стандартный заголовок WooCommerce.
 */
add_filter( 'woocommerce_show_page_title', '__return_false' );

/**
 * Один товар в строке.
 */
add_filter(
	'loop_shop_columns',
	static function (): int {
		return 1;
	}
);
