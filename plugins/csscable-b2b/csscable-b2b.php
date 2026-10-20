<?php
/**
 * Plugin Name: CSS Cable B2B Core
 * Plugin URI: https://csscable.kz/
 * Description: B2B-функции для магазина CSS Cable: договоры, персональные цены, количество, склады, менеджеры и бонусы.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 10.0
 * Author: CSS Cable
 * Text Domain: csscable-b2b
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

final class CSSCable_B2B_Core {

	/**
	 * Версия плагина.
	 */
	public const VERSION = '0.1.0';

	/**
	 * Настройки плагина.
	 */
	public const SETTINGS_OPTION = 'csscable_b2b_settings';

	/**
	 * Единственный экземпляр класса.
	 */
	private static ?self $instance = null;

	/**
	 * Получение экземпляра.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Конструктор.
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Регистрация хуков.
	 */
	private function register_hooks(): void {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) );

		// Стили.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// Настройки.
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Данные пользователя.
		add_action( 'show_user_profile', array( $this, 'render_user_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_fields' ) );

		// Данные товара.
		add_action(
			'woocommerce_product_options_general_product_data',
			array( $this, 'render_product_fields' )
		);

		add_action(
			'woocommerce_admin_process_product_object',
			array( $this, 'save_product_fields' )
		);

		// Персональные цены.
		add_filter(
			'woocommerce_product_get_price',
			array( $this, 'filter_product_price' ),
			20,
			2
		);

		add_filter(
			'woocommerce_product_variation_get_price',
			array( $this, 'filter_product_price' ),
			20,
			2
		);

		add_filter(
			'woocommerce_variation_prices_price',
			array( $this, 'filter_product_price' ),
			20,
			2
		);

		add_filter(
			'woocommerce_variation_prices_sale_price',
			array( $this, 'filter_product_price' ),
			20,
			2
		);

		add_filter(
			'woocommerce_get_variation_prices_hash',
			array( $this, 'variation_prices_hash' ),
			20,
			3
		);

		add_filter(
			'woocommerce_get_price_html',
			array( $this, 'personal_price_html' ),
			30,
			2
		);

		// Количество товара.
		add_filter(
			'woocommerce_quantity_input_args',
			array( $this, 'quantity_input_args' ),
			20,
			2
		);

		add_filter(
			'woocommerce_available_variation',
			array( $this, 'variation_quantity_args' ),
			20,
			3
		);

		add_filter(
			'woocommerce_add_to_cart_validation',
			array( $this, 'validate_add_to_cart_quantity' ),
			20,
			5
		);

		add_filter(
			'woocommerce_update_cart_validation',
			array( $this, 'validate_cart_update_quantity' ),
			20,
			4
		);

		// Данные корзины и заказа.
		add_filter(
			'woocommerce_get_item_data',
			array( $this, 'display_cart_item_data' ),
			20,
			2
		);

		add_action(
			'woocommerce_checkout_create_order_line_item',
			array( $this, 'save_order_item_data' ),
			20,
			4
		);

		add_action(
			'woocommerce_checkout_create_order',
			array( $this, 'save_order_b2b_data' ),
			20,
			2
		);

		// Вкладка товара.
		add_filter(
			'woocommerce_product_tabs',
			array( $this, 'product_tabs' ),
			20
		);

		// Кабинет клиента.
		add_action(
			'woocommerce_account_dashboard',
			array( $this, 'render_account_company_card' ),
			8
		);

		add_shortcode(
			'csscable_company_card',
			array( $this, 'company_card_shortcode' )
		);

		// Кэшбэк.
		add_action(
			'woocommerce_cart_totals_before_order_total',
			array( $this, 'render_cart_cashback' )
		);

		add_action(
			'woocommerce_review_order_before_order_total',
			array( $this, 'render_cart_cashback' )
		);

		add_action(
			'woocommerce_order_status_completed',
			array( $this, 'credit_order_cashback' )
		);

		add_action(
			'woocommerce_order_details_after_order_table',
			array( $this, 'render_order_b2b_information' )
		);
	}

	/**
	 * Переводы.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'csscable-b2b',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * Проверка WooCommerce.
	 */
	public function woocommerce_notice(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong>CSS Cable B2B Core:</strong>
				для работы плагина необходимо установить и активировать WooCommerce.
			</p>
		</div>
		<?php
	}

	/**
	 * Стили frontend.
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		wp_enqueue_style(
			'csscable-b2b',
			plugin_dir_url( __FILE__ ) . 'assets/css/frontend.css',
			array(),
			self::VERSION
		);
	}

	/**
	 * Настройки по умолчанию.
	 */
	public static function default_settings(): array {
		return array(
			'cashback_percent'      => 2,
			'default_unit'          => 'м',
			'default_min_quantity'  => 1,
			'default_quantity_step' => 1,
			'enable_personal_prices' => 'yes',
			'enable_cashback'        => 'yes',
		);
	}

	/**
	 * Получение настроек.
	 */
	public static function get_settings(): array {
		$saved = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::default_settings() );
	}

	/**
	 * Получение одной настройки.
	 */
	public static function get_setting( string $key, mixed $default = null ): mixed {
		$settings = self::get_settings();

		return $settings[ $key ] ?? $default;
	}

	/**
	 * Регистрация страницы настроек.
	 */
	public function register_settings_page(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			'CSS Cable B2B',
			'CSS Cable B2B',
			'manage_woocommerce',
			'csscable-b2b',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Регистрация настроек.
	 */
	public function register_settings(): void {
		register_setting(
			'csscable_b2b_settings_group',
			self::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::default_settings(),
			)
		);
	}

	/**
	 * Очистка настроек.
	 */
	public function sanitize_settings( mixed $settings ): array {
		$settings = is_array( $settings ) ? $settings : array();

		return array(
			'enable_personal_prices' => isset( $settings['enable_personal_prices'] )
				? 'yes'
				: 'no',

			'enable_cashback' => isset( $settings['enable_cashback'] )
				? 'yes'
				: 'no',

			'cashback_percent' => min(
				100,
				max( 0, (float) ( $settings['cashback_percent'] ?? 2 ) )
			),

			'default_unit' => sanitize_text_field(
				$settings['default_unit'] ?? 'м'
			),

			'default_min_quantity' => max(
				0.001,
				(float) ( $settings['default_min_quantity'] ?? 1 )
			),

			'default_quantity_step' => max(
				0.001,
				(float) ( $settings['default_quantity_step'] ?? 1 )
			),
		);
	}

	/**
	 * Страница настроек.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<h1>CSS Cable B2B</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'csscable_b2b_settings_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Персональные цены</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[enable_personal_prices]"
									value="yes"
									<?php checked( $settings['enable_personal_prices'], 'yes' ); ?>
								>
								Включить персональные скидки клиентов
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="cc-default-unit">
								Единица измерения по умолчанию
							</label>
						</th>
						<td>
							<input
								id="cc-default-unit"
								type="text"
								class="regular-text"
								name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[default_unit]"
								value="<?php echo esc_attr( $settings['default_unit'] ); ?>"
							>
							<p class="description">Например: м, шт., бухта, кг.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="cc-default-min-quantity">
								Минимальное количество
							</label>
						</th>
						<td>
							<input
								id="cc-default-min-quantity"
								type="number"
								min="0.001"
								step="0.001"
								name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[default_min_quantity]"
								value="<?php echo esc_attr( $settings['default_min_quantity'] ); ?>"
							>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="cc-default-quantity-step">
								Шаг количества
							</label>
						</th>
						<td>
							<input
								id="cc-default-quantity-step"
								type="number"
								min="0.001"
								step="0.001"
								name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[default_quantity_step]"
								value="<?php echo esc_attr( $settings['default_quantity_step'] ); ?>"
							>
							<p class="description">
								Для кабеля можно указать 100, если заказ должен быть кратен 100 метрам.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Кэшбэк</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[enable_cashback]"
									value="yes"
									<?php checked( $settings['enable_cashback'], 'yes' ); ?>
								>
								Включить начисление кэшбэка
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="cc-cashback-percent">
								Процент кэшбэка
							</label>
						</th>
						<td>
							<input
								id="cc-cashback-percent"
								type="number"
								min="0"
								max="100"
								step="0.01"
								name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[cashback_percent]"
								value="<?php echo esc_attr( $settings['cashback_percent'] ); ?>"
							>
							%
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Поля компании в профиле пользователя.
	 */
	public function render_user_fields( WP_User $user ): void {
		if (
			! current_user_can( 'edit_user', $user->ID )
			&& get_current_user_id() !== $user->ID
		) {
			return;
		}

		wp_nonce_field(
			'csscable_save_user_b2b_fields',
			'csscable_user_b2b_nonce'
		);

		$fields = array(
			'company_name' => array(
				'label' => 'Название компании',
				'type'  => 'text',
			),
			'company_bin' => array(
				'label' => 'БИН / ИИН',
				'type'  => 'text',
			),
			'contract_number' => array(
				'label' => 'Номер договора',
				'type'  => 'text',
			),
			'discount_percent' => array(
				'label' => 'Персональная скидка, %',
				'type'  => 'number',
			),
			'bonus_balance' => array(
				'label' => 'Бонусный баланс',
				'type'  => 'number',
			),
			'manager_name' => array(
				'label' => 'Имя менеджера',
				'type'  => 'text',
			),
			'manager_phone' => array(
				'label' => 'Телефон менеджера',
				'type'  => 'text',
			),
			'manager_email' => array(
				'label' => 'Email менеджера',
				'type'  => 'email',
			),
		);
		?>
		<h2>CSS Cable B2B</h2>

		<table class="form-table" role="presentation">
			<?php foreach ( $fields as $key => $field ) : ?>
				<?php
				$value = get_user_meta(
					$user->ID,
					'_cc_' . $key,
					true
				);
				?>
				<tr>
					<th>
						<label for="cc-<?php echo esc_attr( $key ); ?>">
							<?php echo esc_html( $field['label'] ); ?>
						</label>
					</th>

					<td>
						<input
							id="cc-<?php echo esc_attr( $key ); ?>"
							type="<?php echo esc_attr( $field['type'] ); ?>"
							name="cc_<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							class="regular-text"
							<?php if ( 'number' === $field['type'] ) : ?>
								min="0"
								step="0.01"
							<?php endif; ?>
						>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Сохранение полей пользователя.
	 */
	public function save_user_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$nonce = isset( $_POST['csscable_user_b2b_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['csscable_user_b2b_nonce'] ) )
			: '';

		if (
			! $nonce
			|| ! wp_verify_nonce( $nonce, 'csscable_save_user_b2b_fields' )
		) {
			return;
		}

		$text_fields = array(
			'company_name',
			'company_bin',
			'contract_number',
			'manager_name',
			'manager_phone',
		);

		foreach ( $text_fields as $field ) {
			$value = isset( $_POST[ 'cc_' . $field ] )
				? sanitize_text_field( wp_unslash( $_POST[ 'cc_' . $field ] ) )
				: '';

			update_user_meta(
				$user_id,
				'_cc_' . $field,
				$value
			);
		}

		$manager_email = isset( $_POST['cc_manager_email'] )
			? sanitize_email( wp_unslash( $_POST['cc_manager_email'] ) )
			: '';

		update_user_meta(
			$user_id,
			'_cc_manager_email',
			$manager_email
		);

		$discount = isset( $_POST['cc_discount_percent'] )
			? (float) wp_unslash( $_POST['cc_discount_percent'] )
			: 0;

		$discount = min( 100, max( 0, $discount ) );

		update_user_meta(
			$user_id,
			'_cc_discount_percent',
			$discount
		);

		$bonus_balance = isset( $_POST['cc_bonus_balance'] )
			? (float) wp_unslash( $_POST['cc_bonus_balance'] )
			: 0;

		update_user_meta(
			$user_id,
			'_cc_bonus_balance',
			max( 0, $bonus_balance )
		);
	}

	/**
	 * Поля товара.
	 */
	public function render_product_fields(): void {
		echo '<div class="options_group">';

		echo '<p style="padding: 10px 12px 0; margin: 0;"><strong>CSS Cable B2B</strong></p>';

		woocommerce_wp_text_input( array(
			'id'          => '_cc_unit',
			'label'       => 'Единица измерения',
			'placeholder' => (string) self::get_setting( 'default_unit', 'м' ),
			'description' => 'Например: м, шт., бухта.',
			'desc_tip'    => true,
		) );

		woocommerce_wp_text_input( array(
			'id'                => '_cc_min_quantity',
			'label'             => 'Минимальное количество',
			'type'              => 'number',
			'description'       => 'Минимальное количество для добавления в корзину.',
			'desc_tip'          => true,
			'custom_attributes' => array(
				'min'  => '0.001',
				'step' => '0.001',
			),
		) );

		woocommerce_wp_text_input( array(
			'id'                => '_cc_quantity_step',
			'label'             => 'Шаг количества',
			'type'              => 'number',
			'description'       => 'Например: 100 — количество будет 100, 200, 300 и т. д.',
			'desc_tip'          => true,
			'custom_attributes' => array(
				'min'  => '0.001',
				'step' => '0.001',
			),
		) );

		woocommerce_wp_text_input( array(
			'id'          => '_cc_gost',
			'label'       => 'ГОСТ / стандарт',
			'placeholder' => 'ГОСТ 31996-2012',
		) );

		woocommerce_wp_textarea_input( array(
			'id'          => '_cc_warehouse_stock',
			'label'       => 'Остатки по складам',
			'placeholder' => "Караганда|5200\nАстана|1800\nАлматы|0",
			'description' => 'Каждый склад с новой строки в формате: Название|Количество.',
			'desc_tip'    => true,
			'rows'        => 5,
		) );

		echo '</div>';
	}

	/**
	 * Сохранение полей товара.
	 */
	public function save_product_fields( WC_Product $product ): void {
		$text_fields = array(
			'_cc_unit',
			'_cc_gost',
		);

		foreach ( $text_fields as $field ) {
			$value = isset( $_POST[ $field ] )
				? sanitize_text_field( wp_unslash( $_POST[ $field ] ) )
				: '';

			$product->update_meta_data( $field, $value );
		}

		$min_quantity = isset( $_POST['_cc_min_quantity'] )
			? (float) wp_unslash( $_POST['_cc_min_quantity'] )
			: '';

		$quantity_step = isset( $_POST['_cc_quantity_step'] )
			? (float) wp_unslash( $_POST['_cc_quantity_step'] )
			: '';

		$product->update_meta_data(
			'_cc_min_quantity',
			'' === $min_quantity ? '' : max( 0.001, $min_quantity )
		);

		$product->update_meta_data(
			'_cc_quantity_step',
			'' === $quantity_step ? '' : max( 0.001, $quantity_step )
		);

		$warehouse_stock = isset( $_POST['_cc_warehouse_stock'] )
			? sanitize_textarea_field(
				wp_unslash( $_POST['_cc_warehouse_stock'] )
			)
			: '';

		$product->update_meta_data(
			'_cc_warehouse_stock',
			$warehouse_stock
		);
	}

	/**
	 * Персональная скидка текущего пользователя.
	 */
	public static function get_user_discount( int $user_id = 0 ): float {
		if ( 'yes' !== self::get_setting( 'enable_personal_prices', 'yes' ) ) {
			return 0;
		}

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return 0;
		}

		$discount = (float) get_user_meta(
			$user_id,
			'_cc_discount_percent',
			true
		);

		return min( 100, max( 0, $discount ) );
	}

	/**
	 * Расчёт персональной цены.
	 */
	public function filter_product_price(
		mixed $price,
		WC_Product $product
	): mixed {
		if ( '' === $price || null === $price ) {
			return $price;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $price;
		}

		$discount = self::get_user_discount();

		if ( $discount <= 0 ) {
			return $price;
		}

		$price = (float) $price;

		return wc_format_decimal(
			$price * ( 1 - ( $discount / 100 ) ),
			wc_get_price_decimals()
		);
	}

	/**
	 * Разделение кеша цен вариаций по скидке клиента.
	 */
	public function variation_prices_hash(
		array $hash,
		WC_Product $product,
		bool $for_display
	): array {
		$hash['csscable_discount'] = self::get_user_discount();

		return $hash;
	}

	/**
	 * HTML персональной цены.
	 */
	public function personal_price_html(
		string $price_html,
		WC_Product $product
	): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $price_html;
		}

		if ( $product->is_type( 'variable' ) ) {
			return $price_html;
		}

		$discount = self::get_user_discount();

		if ( $discount <= 0 ) {
			return $price_html;
		}

		$regular = (float) $product->get_regular_price( 'edit' );
		$sale    = (float) $product->get_sale_price( 'edit' );

		$base_price = $regular;

		if ( $sale > 0 && $sale < $regular ) {
			$base_price = $sale;
		}

		if ( $base_price <= 0 ) {
			return $price_html;
		}

		$personal_price = $base_price * ( 1 - ( $discount / 100 ) );

		$base_display = wc_get_price_to_display(
			$product,
			array( 'price' => $base_price )
		);

		$personal_display = wc_get_price_to_display(
			$product,
			array( 'price' => $personal_price )
		);

		return sprintf(
			'<span class="cc-b2b-price">
				<del class="cc-b2b-price__base">%1$s</del>
				<ins class="cc-b2b-price__personal">%2$s</ins>
			</span>',
			wp_kses_post( wc_price( $base_display ) ),
			wp_kses_post( wc_price( $personal_display ) )
		);
	}

	/**
	 * Единица измерения.
	 */
	public static function get_product_unit( WC_Product|int $product ): string {
		if ( is_int( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product instanceof WC_Product ) {
			return (string) self::get_setting( 'default_unit', 'м' );
		}

		$unit = (string) $product->get_meta( '_cc_unit', true );

		if ( '' === $unit ) {
			$unit = (string) self::get_setting( 'default_unit', 'м' );
		}

		return $unit;
	}

	/**
	 * Минимальное количество.
	 */
	public static function get_min_quantity( WC_Product $product ): float {
		$value = $product->get_meta( '_cc_min_quantity', true );

		if ( '' === $value ) {
			$value = self::get_setting( 'default_min_quantity', 1 );
		}

		return max( 0.001, (float) $value );
	}

	/**
	 * Шаг количества.
	 */
	public static function get_quantity_step( WC_Product $product ): float {
		$value = $product->get_meta( '_cc_quantity_step', true );

		if ( '' === $value ) {
			$value = self::get_setting( 'default_quantity_step', 1 );
		}

		return max( 0.001, (float) $value );
	}

	/**
	 * Настройка поля количества.
	 */
	public function quantity_input_args(
		array $args,
		WC_Product $product
	): array {
		$min  = self::get_min_quantity( $product );
		$step = self::get_quantity_step( $product );

		$args['min_value']   = $min;
		$args['input_value'] = max(
			$min,
			(float) ( $args['input_value'] ?? $min )
		);
		$args['step']        = $step;

		return $args;
	}

	/**
	 * Количество для вариативного товара.
	 */
	public function variation_quantity_args(
		array $data,
		WC_Product $parent,
		WC_Product_Variation $variation
	): array {
		$data['min_qty'] = self::get_min_quantity( $variation );

		$data['input_value'] = self::get_min_quantity( $variation );

		$data['step'] = self::get_quantity_step( $variation );

		return $data;
	}

	/**
	 * Проверка кратности количества.
	 */
	private function quantity_is_valid(
		WC_Product $product,
		float $quantity
	): bool {
		$min  = self::get_min_quantity( $product );
		$step = self::get_quantity_step( $product );

		if ( $quantity < $min ) {
			wc_add_notice(
				sprintf(
					'Минимальное количество товара «%1$s» — %2$s %3$s.',
					$product->get_name(),
					wc_format_localized_decimal( $min ),
					self::get_product_unit( $product )
				),
				'error'
			);

			return false;
		}

		$difference = $quantity - $min;
		$remainder  = fmod( $difference, $step );

		$is_multiple = abs( $remainder ) < 0.0001
			|| abs( $remainder - $step ) < 0.0001;

		if ( ! $is_multiple ) {
			wc_add_notice(
				sprintf(
					'Количество товара «%1$s» должно изменяться с шагом %2$s %3$s. Допустимые значения начинаются с %4$s.',
					$product->get_name(),
					wc_format_localized_decimal( $step ),
					self::get_product_unit( $product ),
					wc_format_localized_decimal( $min )
				),
				'error'
			);

			return false;
		}

		return true;
	}

	/**
	 * Проверка при добавлении в корзину.
	 */
	public function validate_add_to_cart_quantity(
		bool $passed,
		int $product_id,
		float $quantity,
		int $variation_id = 0,
		array $variations = array()
	): bool {
		$id = $variation_id ?: $product_id;

		$product = wc_get_product( $id );

		if ( ! $product ) {
			return $passed;
		}

		return $passed && $this->quantity_is_valid(
			$product,
			(float) $quantity
		);
	}

	/**
	 * Проверка при обновлении корзины.
	 */
	public function validate_cart_update_quantity(
		bool $passed,
		string $cart_item_key,
		array $values,
		float $quantity
	): bool {
		$product = $values['data'] ?? null;

		if ( ! $product instanceof WC_Product ) {
			return $passed;
		}

		return $passed && $this->quantity_is_valid(
			$product,
			(float) $quantity
		);
	}

	/**
	 * B2B-данные в корзине.
	 */
	public function display_cart_item_data(
		array $item_data,
		array $cart_item
	): array {
		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof WC_Product ) {
			return $item_data;
		}

		$item_data[] = array(
			'key'   => 'Единица измерения',
			'value' => self::get_product_unit( $product ),
		);

		$item_data[] = array(
			'key'   => 'Шаг заказа',
			'value' => sprintf(
				'%1$s %2$s',
				wc_format_localized_decimal(
					self::get_quantity_step( $product )
				),
				self::get_product_unit( $product )
			),
		);

		return $item_data;
	}

	/**
	 * Сохранение параметров товара в позицию заказа.
	 */
	public function save_order_item_data(
		WC_Order_Item_Product $item,
		string $cart_item_key,
		array $values,
		WC_Order $order
	): void {
		$product = $values['data'] ?? null;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$item->add_meta_data(
			'Единица измерения',
			self::get_product_unit( $product ),
			true
		);

		$gost = $product->get_meta( '_cc_gost', true );

		if ( $gost ) {
			$item->add_meta_data(
				'ГОСТ',
				$gost,
				true
			);
		}
	}

	/**
	 * Сохранение информации о компании в заказ.
	 */
	public function save_order_b2b_data(
		WC_Order $order,
		array $data
	): void {
		$user_id = $order->get_user_id();

		if ( ! $user_id ) {
			return;
		}

		$map = array(
			'_cc_company_name'     => '_cc_company_name',
			'_cc_company_bin'      => '_cc_company_bin',
			'_cc_contract_number'  => '_cc_contract_number',
			'_cc_manager_name'     => '_cc_manager_name',
			'_cc_manager_phone'    => '_cc_manager_phone',
			'_cc_manager_email'    => '_cc_manager_email',
			'_cc_discount_percent' => '_cc_discount_percent',
		);

		foreach ( $map as $order_key => $user_key ) {
			$value = get_user_meta( $user_id, $user_key, true );

			if ( '' !== $value ) {
				$order->update_meta_data(
					$order_key,
					$value
				);
			}
		}

		if ( 'yes' === self::get_setting( 'enable_cashback', 'yes' ) ) {
			$cashback = self::calculate_cashback(
				(float) $order->get_total()
			);

			$order->update_meta_data(
				'_cc_cashback_amount',
				$cashback
			);
		}
	}

	/**
	 * Парсинг складов.
	 */
	public static function get_warehouses( WC_Product|int $product ): array {
		if ( is_int( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$raw = (string) $product->get_meta(
			'_cc_warehouse_stock',
			true
		);

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$warehouses = array();
		$lines      = preg_split( '/\r\n|\r|\n/', $raw );

		foreach ( $lines as $line ) {
			$parts = array_map(
				'trim',
				explode( '|', $line, 2 )
			);

			if ( empty( $parts[0] ) ) {
				continue;
			}

			$warehouses[] = array(
				'name'     => sanitize_text_field( $parts[0] ),
				'quantity' => isset( $parts[1] )
					? (float) str_replace( ',', '.', $parts[1] )
					: 0,
			);
		}

		return $warehouses;
	}

	/**
	 * Вкладка товара.
	 */
	public function product_tabs( array $tabs ): array {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return $tabs;
		}

		$warehouses = self::get_warehouses( $product );
		$gost       = $product->get_meta( '_cc_gost', true );

		if ( empty( $warehouses ) && ! $gost ) {
			return $tabs;
		}

		$tabs['csscable_b2b'] = array(
			'title'    => 'Наличие и условия',
			'priority' => 15,
			'callback' => array( $this, 'render_product_b2b_tab' ),
		);

		return $tabs;
	}

	/**
	 * Содержимое B2B-вкладки.
	 */
	public function render_product_b2b_tab(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$warehouses = self::get_warehouses( $product );
		$gost       = $product->get_meta( '_cc_gost', true );
		$unit       = self::get_product_unit( $product );
		$min        = self::get_min_quantity( $product );
		$step       = self::get_quantity_step( $product );
		?>
		<div class="cc-b2b-product-info">
			<div class="cc-b2b-product-info__conditions">
				<div>
					<span>Единица измерения</span>
					<strong><?php echo esc_html( $unit ); ?></strong>
				</div>

				<div>
					<span>Минимальный заказ</span>
					<strong>
						<?php
						echo esc_html(
							wc_format_localized_decimal( $min ) . ' ' . $unit
						);
						?>
					</strong>
				</div>

				<div>
					<span>Шаг количества</span>
					<strong>
						<?php
						echo esc_html(
							wc_format_localized_decimal( $step ) . ' ' . $unit
						);
						?>
					</strong>
				</div>

				<?php if ( $gost ) : ?>
					<div>
						<span>Стандарт</span>
						<strong><?php echo esc_html( $gost ); ?></strong>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $warehouses ) : ?>
				<h3>Остатки по складам</h3>

				<div class="cc-b2b-warehouses">
					<?php foreach ( $warehouses as $warehouse ) : ?>
						<div class="cc-b2b-warehouse">
							<div>
								<span class="cc-b2b-warehouse__dot"></span>
								<strong>
									<?php echo esc_html( $warehouse['name'] ); ?>
								</strong>
							</div>

							<span>
								<?php
								echo esc_html(
									number_format_i18n(
										$warehouse['quantity']
									) . ' ' . $unit
								);
								?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Карточка компании.
	 */
	public function render_account_company_card(): void {
		echo $this->get_company_card_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Шорткод карточки компании.
	 */
	public function company_card_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		return $this->get_company_card_html();
	}

	/**
	 * HTML карточки компании.
	 */
	private function get_company_card_html(): string {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '';
		}

		$company  = get_user_meta( $user_id, '_cc_company_name', true );
		$bin      = get_user_meta( $user_id, '_cc_company_bin', true );
		$contract = get_user_meta( $user_id, '_cc_contract_number', true );
		$discount = self::get_user_discount( $user_id );
		$bonus    = (float) get_user_meta( $user_id, '_cc_bonus_balance', true );

		$manager_name  = get_user_meta( $user_id, '_cc_manager_name', true );
		$manager_phone = get_user_meta( $user_id, '_cc_manager_phone', true );
		$manager_email = get_user_meta( $user_id, '_cc_manager_email', true );

		ob_start();
		?>
		<div class="cc-b2b-account">
			<div class="cc-b2b-account__header">
				<div>
					<span class="cc-b2b-account__caption">Ваша компания</span>
					<h2>
						<?php
						echo esc_html(
							$company ?: 'Компания не указана'
						);
						?>
					</h2>

					<?php if ( $bin ) : ?>
						<p>БИН: <?php echo esc_html( $bin ); ?></p>
					<?php endif; ?>
				</div>

				<?php if ( $contract ) : ?>
					<span class="cc-b2b-contract">
						Договор <?php echo esc_html( $contract ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="cc-b2b-account__stats">
				<div class="cc-b2b-stat">
					<span>Персональная скидка</span>
					<strong>
						<?php echo esc_html( wc_format_localized_decimal( $discount ) ); ?>%
					</strong>
				</div>

				<div class="cc-b2b-stat">
					<span>Бонусный баланс</span>
					<strong>
						<?php echo wp_kses_post( wc_price( $bonus ) ); ?>
					</strong>
				</div>

				<div class="cc-b2b-stat">
					<span>Номер договора</span>
					<strong>
						<?php echo esc_html( $contract ?: '—' ); ?>
					</strong>
				</div>
			</div>

			<?php if ( $manager_name || $manager_phone || $manager_email ) : ?>
				<div class="cc-b2b-manager">
					<div class="cc-b2b-manager__avatar">
						<?php
						echo esc_html(
							$manager_name
								? mb_strtoupper( mb_substr( $manager_name, 0, 1 ) )
								: 'М'
						);
						?>
					</div>

					<div class="cc-b2b-manager__content">
						<span>Ваш персональный менеджер</span>
						<strong>
							<?php echo esc_html( $manager_name ?: 'Менеджер' ); ?>
						</strong>

						<div>
							<?php if ( $manager_phone ) : ?>
								<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $manager_phone ) ); ?>">
									<?php echo esc_html( $manager_phone ); ?>
								</a>
							<?php endif; ?>

							<?php if ( $manager_email ) : ?>
								<a href="mailto:<?php echo esc_attr( $manager_email ); ?>">
									<?php echo esc_html( $manager_email ); ?>
								</a>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Расчёт кэшбэка.
	 */
	public static function calculate_cashback( float $amount ): float {
		if ( 'yes' !== self::get_setting( 'enable_cashback', 'yes' ) ) {
			return 0;
		}

		$percent = (float) self::get_setting(
			'cashback_percent',
			2
		);

		return round(
			$amount * ( $percent / 100 ),
			wc_get_price_decimals()
		);
	}

	/**
	 * Кэшбэк в корзине и checkout.
	 */
	public function render_cart_cashback(): void {
		if (
			! function_exists( 'WC' )
			|| ! WC()->cart
			|| 'yes' !== self::get_setting( 'enable_cashback', 'yes' )
		) {
			return;
		}

		$amount = (float) WC()->cart->get_cart_contents_total();
		$value  = self::calculate_cashback( $amount );

		if ( $value <= 0 ) {
			return;
		}
		?>
		<tr class="cc-b2b-cashback-row">
			<th>Будет начислено бонусов</th>
			<td data-title="Будет начислено бонусов">
				<strong>+<?php echo wp_kses_post( wc_price( $value ) ); ?></strong>
			</td>
		</tr>
		<?php
	}

	/**
	 * Начисление кэшбэка после завершения заказа.
	 */
	public function credit_order_cashback( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		if ( 'yes' === $order->get_meta( '_cc_cashback_credited', true ) ) {
			return;
		}

		$user_id = $order->get_user_id();

		if ( ! $user_id ) {
			return;
		}

		$cashback = (float) $order->get_meta(
			'_cc_cashback_amount',
			true
		);

		if ( $cashback <= 0 ) {
			return;
		}

		$current_balance = (float) get_user_meta(
			$user_id,
			'_cc_bonus_balance',
			true
		);

		update_user_meta(
			$user_id,
			'_cc_bonus_balance',
			$current_balance + $cashback
		);

		$order->update_meta_data(
			'_cc_cashback_credited',
			'yes'
		);

		$order->add_order_note(
			sprintf(
				'Клиенту начислен кэшбэк: %s',
				wp_strip_all_tags( wc_price( $cashback ) )
			)
		);

		$order->save();
	}

	/**
	 * B2B-информация после таблицы заказа.
	 */
	public function render_order_b2b_information(
		WC_Order $order
	): void {
		$contract = $order->get_meta( '_cc_contract_number', true );
		$company  = $order->get_meta( '_cc_company_name', true );
		$bin      = $order->get_meta( '_cc_company_bin', true );
		$cashback = (float) $order->get_meta( '_cc_cashback_amount', true );

		if ( ! $contract && ! $company && ! $bin && $cashback <= 0 ) {
			return;
		}
		?>
		<section class="cc-b2b-order-info">
			<h2>Информация по договору</h2>

			<div class="cc-b2b-order-info__grid">
				<?php if ( $company ) : ?>
					<div>
						<span>Компания</span>
						<strong><?php echo esc_html( $company ); ?></strong>
					</div>
				<?php endif; ?>

				<?php if ( $bin ) : ?>
					<div>
						<span>БИН</span>
						<strong><?php echo esc_html( $bin ); ?></strong>
					</div>
				<?php endif; ?>

				<?php if ( $contract ) : ?>
					<div>
						<span>Договор</span>
						<strong><?php echo esc_html( $contract ); ?></strong>
					</div>
				<?php endif; ?>

				<?php if ( $cashback > 0 ) : ?>
					<div>
						<span>Кэшбэк за заказ</span>
						<strong class="cc-b2b-success">
							+<?php echo wp_kses_post( wc_price( $cashback ) ); ?>
						</strong>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}
}

/**
 * Совместимость с WooCommerce HPOS.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if (
			class_exists(
				\Automattic\WooCommerce\Utilities\FeaturesUtil::class
			)
		) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Настройки по умолчанию при активации.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( false === get_option( CSSCable_B2B_Core::SETTINGS_OPTION, false ) ) {
			add_option(
				CSSCable_B2B_Core::SETTINGS_OPTION,
				CSSCable_B2B_Core::default_settings()
			);
		}
	}
);

/**
 * Запуск.
 */
CSSCable_B2B_Core::instance();

/**
 * Вспомогательная функция: номер договора.
 */
function csscable_b2b_contract( int $user_id = 0 ): string {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	return (string) get_user_meta(
		$user_id,
		'_cc_contract_number',
		true
	);
}

/**
 * Вспомогательная функция: компания.
 */
function csscable_b2b_company_name( int $user_id = 0 ): string {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	return (string) get_user_meta(
		$user_id,
		'_cc_company_name',
		true
	);
}

/**
 * Вспомогательная функция: единица товара.
 */
function csscable_b2b_product_unit( WC_Product|int $product ): string {
	return CSSCable_B2B_Core::get_product_unit( $product );
}

/**
 * Вспомогательная функция: склады.
 */
function csscable_b2b_product_warehouses( WC_Product|int $product ): array {
	return CSSCable_B2B_Core::get_warehouses( $product );
}
