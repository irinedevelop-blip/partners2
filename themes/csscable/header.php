<?php
/**
 * Header.
 *
 * @package CSSCable
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="screen-reader-text" href="#primary">
	<?php esc_html_e( 'Перейти к содержимому', 'csscable' ); ?>
</a>

<div class="cc-mobile-overlay" data-sidebar-close></div>

<div class="cc-shell">
	<aside class="cc-sidebar" id="cc-sidebar">
		<div class="cc-sidebar__header">
			<a class="cc-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php if ( has_custom_logo() ) : ?>
					<?php the_custom_logo(); ?>
				<?php else : ?>
					<span class="cc-brand__mark">ЦСС</span>
					<span class="cc-brand__name">КАБЕЛЬ</span>
				<?php endif; ?>
			</a>

			<button
				class="cc-sidebar__close"
				type="button"
				data-sidebar-close
				aria-label="<?php esc_attr_e( 'Закрыть меню', 'csscable' ); ?>"
			>
				×
			</button>
		</div>

		<nav class="cc-navigation" aria-label="<?php esc_attr_e( 'Навигация', 'csscable' ); ?>">
			<a
				class="cc-navigation__item <?php echo csscable_is_active( 'home' ) ? 'is-active' : ''; ?>"
				href="<?php echo esc_url( home_url( '/' ) ); ?>"
			>
				<?php csscable_icon( 'home' ); ?>
				<span><?php esc_html_e( 'Обзор', 'csscable' ); ?></span>
			</a>

			<a
				class="cc-navigation__item <?php echo csscable_is_active( 'catalog' ) ? 'is-active' : ''; ?>"
				href="<?php echo esc_url( csscable_shop_url() ); ?>"
			>
				<?php csscable_icon( 'catalog' ); ?>
				<span><?php esc_html_e( 'Каталог', 'csscable' ); ?></span>
			</a>

			<a
				class="cc-navigation__item <?php echo csscable_is_active( 'cart' ) ? 'is-active' : ''; ?>"
				href="<?php echo esc_url( csscable_cart_url() ); ?>"
			>
				<?php csscable_icon( 'cart' ); ?>
				<span><?php esc_html_e( 'Корзина', 'csscable' ); ?></span>

				<span class="cc-navigation__badge cc-cart-count">
					<?php echo esc_html( csscable_cart_count() ); ?>
				</span>
			</a>

			<a
				class="cc-navigation__item"
				href="<?php echo esc_url( csscable_account_url() . 'orders/' ); ?>"
			>
				<?php csscable_icon( 'orders' ); ?>
				<span><?php esc_html_e( 'Мои заказы', 'csscable' ); ?></span>
			</a>

			<a
				class="cc-navigation__item"
				href="<?php echo esc_url( csscable_account_url() . 'downloads/' ); ?>"
			>
				<?php csscable_icon( 'documents' ); ?>
				<span><?php esc_html_e( 'Документы', 'csscable' ); ?></span>
			</a>

			<a
				class="cc-navigation__item <?php echo csscable_is_active( 'account' ) ? 'is-active' : ''; ?>"
				href="<?php echo esc_url( csscable_account_url() ); ?>"
			>
				<?php csscable_icon( 'company' ); ?>
				<span><?php esc_html_e( 'Моя компания', 'csscable' ); ?></span>
			</a>
		</nav>

		<div class="cc-sidebar__bottom">
			<?php if ( is_user_logged_in() ) : ?>
				<?php $current_user = wp_get_current_user(); ?>

				<div class="cc-user-card">
					<div class="cc-user-card__avatar">
						<?php
						echo esc_html(
							mb_strtoupper(
								mb_substr( $current_user->display_name, 0, 1 )
							)
						);
						?>
					</div>

					<div class="cc-user-card__content">
						<strong><?php echo esc_html( $current_user->display_name ); ?></strong>
						<span><?php echo esc_html( $current_user->user_email ); ?></span>
					</div>
				</div>
			<?php else : ?>
				<a class="cc-login-link" href="<?php echo esc_url( csscable_account_url() ); ?>">
					<?php csscable_icon( 'user' ); ?>
					<?php esc_html_e( 'Войти в кабинет', 'csscable' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</aside>

	<div class="cc-workspace">
		<header class="cc-topbar">
			<button
				class="cc-mobile-menu"
				type="button"
				data-sidebar-open
				aria-controls="cc-sidebar"
				aria-label="<?php esc_attr_e( 'Открыть меню', 'csscable' ); ?>"
			>
				<?php csscable_icon( 'menu' ); ?>
			</button>

			<div class="cc-topbar__title">
				<?php
				if ( function_exists( 'is_shop' ) && is_shop() ) {
					esc_html_e( 'Каталог продукции', 'csscable' );
				} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
					esc_html_e( 'Корзина', 'csscable' );
				} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
					esc_html_e( 'Оформление заказа', 'csscable' );
				} elseif ( is_singular() ) {
					echo esc_html( get_the_title() );
				} else {
					bloginfo( 'name' );
				}
				?>
			</div>

			<div class="cc-topbar__spacer"></div>

			<?php
$contract_number = function_exists( 'csscable_b2b_contract' )
	? csscable_b2b_contract()
	: '';
?>

<?php if ( is_user_logged_in() && $contract_number ) : ?>
	<div class="cc-topbar__contract">
		<span><?php esc_html_e( 'Договор', 'csscable' ); ?></span>

		<strong>
			<?php echo esc_html( $contract_number ); ?>
		</strong>
	</div>
<?php endif; ?>


			<a class="cc-topbar__cart" href="<?php echo esc_url( csscable_cart_url() ); ?>">
				<?php csscable_icon( 'cart' ); ?>

				<span class="cc-cart-count">
					<?php echo esc_html( csscable_cart_count() ); ?>
				</span>
			</a>
		</header>

		<main id="primary" class="cc-main">
