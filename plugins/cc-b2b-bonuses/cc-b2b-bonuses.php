<?php
/**
 * Plugin Name: ЦСС B2B — Бонусная система
 * Description: Дашборды, единая персональная скидка, реферальные бонусы, оплата бонусами и запросы на вывод.
 * Version: 2.2.0
 * Author: ЦСС Кабель
 * Text Domain: cc-b2b-bonuses
 */

defined('ABSPATH') || exit;

final class CC_B2B_Bonuses
{
    const VERSION = '2.2.0';

    const MANAGER_META       = '_cc_b2b_manager_id';
    const COMPANY_META       = '_cc_b2b_company';
    const BIN_META           = '_cc_b2b_bin';
    const DISCOUNT_META      = '_cc_discount_percent';
    const OLD_RATE_META      = '_ccb_purchase_bonus_rate';
    const REFERRAL_RATE_META = '_ccb_referral_bonus_rate';
    const REFERRER_META      = '_ccb_referrer_user_id';
    const REFERRAL_CODE_META = '_ccb_referral_code';

    const ALLOW_PAYMENT_META = '_ccb_allow_bonus_payment';
    const MAX_PAYMENT_META   = '_ccb_max_bonus_payment_percent';
    const MIN_PAYMENT_META   = '_ccb_min_bonus_payment';
    const MIN_WITHDRAW_META  = '_ccb_min_withdrawal';
    const MIGRATED_META      = '_ccb_opening_balance_migrated_v22';

    const REFERRAL_COOKIE = 'ccb_referral_code';

    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'plugins_loaded'], 100);

        add_action('init', [$this, 'maybe_install']);
        add_action('init', [$this, 'capture_referral_code']);
        add_action('init', [$this, 'migrate_users'], 30);

        add_action('user_register', [$this, 'handle_new_user'], 20);

        add_action(
            'woocommerce_order_status_completed',
            [$this, 'handle_completed_order'],
            20
        );

        add_action(
            'woocommerce_order_status_changed',
            [$this, 'handle_order_status_changed'],
            20,
            4
        );

        add_action(
            'woocommerce_review_order_before_payment',
            [$this, 'render_checkout_bonus']
        );

        add_action(
            'woocommerce_checkout_update_order_review',
            [$this, 'update_checkout_bonus']
        );

        add_action(
            'woocommerce_cart_calculate_fees',
            [$this, 'apply_bonus_to_cart'],
            30
        );

        add_action(
            'woocommerce_checkout_order_processed',
            [$this, 'save_order_bonus_payment'],
            20,
            3
        );

        add_action(
            'template_redirect',
            [$this, 'handle_frontend_actions'],
            5
        );

        add_shortcode('cc_b2b_bonuses', [$this, 'bonus_shortcode']);

        add_filter(
            'do_shortcode_tag',
            [$this, 'integrate_into_cabinet'],
            20,
            4
        );

        add_action('show_user_profile', [$this, 'profile_fields'], 50);
        add_action('edit_user_profile', [$this, 'profile_fields'], 50);
        add_action('personal_options_update', [$this, 'save_profile_fields'], 50);
        add_action('edit_user_profile_update', [$this, 'save_profile_fields'], 50);

        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_notices', [$this, 'dependency_notice']);
    }

    public static function activate()
    {
        self::create_tables();
        update_option('ccb_bonus_plugin_version', self::VERSION);
    }

    public function maybe_install()
    {
        if (get_option('ccb_bonus_plugin_version') !== self::VERSION) {
            self::create_tables();
            update_option('ccb_bonus_plugin_version', self::VERSION);
        }
    }

    private static function create_tables()
    {
        global $wpdb;

        $charset     = $wpdb->get_charset_collate();
        $ledger      = $wpdb->prefix . 'cc_b2b_bonus_ledger';
        $withdrawals = $wpdb->prefix . 'cc_b2b_bonus_withdrawals';

        $ledger_sql = "CREATE TABLE {$ledger} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            source_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            entry_type varchar(50) NOT NULL,
            amount decimal(18,4) NOT NULL DEFAULT 0,
            rate decimal(8,4) NOT NULL DEFAULT 0,
            note text NULL,
            entry_key varchar(191) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY entry_key (entry_key),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY entry_type (entry_type),
            KEY created_at (created_at)
        ) {$charset};";

        $withdrawal_sql = "CREATE TABLE {$withdrawals} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            manager_id bigint(20) unsigned NOT NULL DEFAULT 0,
            amount decimal(18,4) NOT NULL DEFAULT 0,
            payment_method varchar(100) NOT NULL DEFAULT '',
            requisites text NULL,
            client_comment text NULL,
            manager_comment text NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY manager_id (manager_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta($ledger_sql);
        dbDelta($withdrawal_sql);
    }

    private function ledger_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'cc_b2b_bonus_ledger';
    }

    private function withdrawals_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'cc_b2b_bonus_withdrawals';
    }

    public function dependency_notice()
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p><strong>ЦСС B2B:</strong> активируйте WooCommerce.</p></div>';
        }

        if (!class_exists('CC_B2B_Cabinet')) {
            echo '<div class="notice notice-warning"><p><strong>ЦСС B2B:</strong> активируйте «ЦСС B2B Кабинет».</p></div>';
        }
    }

    public function plugins_loaded()
    {
        if (
            class_exists('CSSCable_B2B_Core') &&
            method_exists('CSSCable_B2B_Core', 'instance')
        ) {
            $core = CSSCable_B2B_Core::instance();

            foreach (
                [
                    'credit_order_cashback',
                    'add_order_cashback',
                    'award_order_bonus',
                    'process_order_bonus',
                ] as $method
            ) {
                remove_action(
                    'woocommerce_order_status_completed',
                    [$core, $method],
                    10
                );

                remove_action(
                    'woocommerce_order_status_completed',
                    [$core, $method],
                    20
                );
            }

            remove_action('show_user_profile', [$core, 'render_user_fields']);
            remove_action('edit_user_profile', [$core, 'render_user_fields']);
            remove_action('personal_options_update', [$core, 'save_user_fields']);
            remove_action('edit_user_profile_update', [$core, 'save_user_fields']);
        }

        $this->remove_class_callback(
            'show_user_profile',
            'CC_B2B_Cabinet',
            'user_fields'
        );

        $this->remove_class_callback(
            'edit_user_profile',
            'CC_B2B_Cabinet',
            'user_fields'
        );

        $this->remove_class_callback(
            'personal_options_update',
            'CC_B2B_Cabinet',
            'save_user_fields'
        );

        $this->remove_class_callback(
            'edit_user_profile_update',
            'CC_B2B_Cabinet',
            'save_user_fields'
        );
    }

    private function remove_class_callback($hook, $class, $method)
    {
        global $wp_filter;

        if (
            empty($wp_filter[$hook]) ||
            empty($wp_filter[$hook]->callbacks)
        ) {
            return;
        }

        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if (
                    empty($callback['function']) ||
                    !is_array($callback['function'])
                ) {
                    continue;
                }

                $object = $callback['function'][0];
                $name   = $callback['function'][1];

                if (
                    is_object($object) &&
                    get_class($object) === $class &&
                    $name === $method
                ) {
                    remove_action($hook, [$object, $name], $priority);
                }
            }
        }
    }

    private function contains($text, $search)
    {
        if (function_exists('mb_stripos')) {
            return mb_stripos($text, $search, 0, 'UTF-8') !== false;
        }

        return stripos($text, $search) !== false;
    }

    private function has_role_type($user, array $slugs, array $names)
    {
        if (!$user instanceof WP_User || !$user->exists()) {
            return false;
        }

        $registered = wp_roles()->roles;

        foreach ((array) $user->roles as $slug) {
            if (in_array($slug, $slugs, true)) {
                return true;
            }

            $role_name = $registered[$slug]['name'] ?? '';
            $text      = $slug . ' ' . $role_name;

            foreach ($names as $name) {
                if ($this->contains($text, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function is_manager($user = null)
    {
        $user = $user ?: wp_get_current_user();

        if (!$user instanceof WP_User || !$user->exists()) {
            return false;
        }

        if (
            user_can($user, 'manage_options') ||
            user_can($user, 'cc_manage_clients')
        ) {
            return true;
        }

        return $this->has_role_type(
            $user,
            [
                'cc_b2b_manager',
                'b2b_manager',
                'css_b2b_manager',
                'lcc_b2b_manager',
                'manager',
            ],
            ['Менеджер', 'Manager']
        );
    }

    private function is_client($user = null)
    {
        $user = $user ?: wp_get_current_user();

        if (!$user instanceof WP_User || !$user->exists()) {
            return false;
        }

        if (
            user_can($user, 'cc_view_own_orders') &&
            !user_can($user, 'cc_manage_clients')
        ) {
            return true;
        }

        return $this->has_role_type(
            $user,
            [
                'cc_b2b_client',
                'b2b_client',
                'b2b_customer',
                'b2b_buyer',
                'b2b_purchaser',
                'b2b_zakupshchik',
                'css_b2b_buyer',
                'lcc_b2b_buyer',
                'client',
                'customer',
            ],
            [
                'Клиент',
                'Закупщик',
                'Покупатель',
                'Client',
                'Customer',
                'Buyer',
                'Purchaser',
            ]
        );
    }

    private function meta_value($user_id, array $keys)
    {
        foreach ($keys as $key) {
            $value = get_user_meta($user_id, $key, true);

            if ($value !== '' && $value !== null && $value !== false) {
                return $value;
            }
        }

        return '';
    }

    private function company($user_id)
    {
        return trim((string) $this->meta_value(
            $user_id,
            [
                self::COMPANY_META,
                '_cc_company_name',
                'cc_b2b_company',
                'billing_company',
            ]
        ));
    }

    private function full_name($user)
    {
        if (!$user instanceof WP_User) {
            return '';
        }

        $name = trim(
            (string) get_user_meta($user->ID, 'first_name', true) .
            ' ' .
            (string) get_user_meta($user->ID, 'last_name', true)
        );

        return $name !== '' ? $name : $user->display_name;
    }

    private function client_title($user)
    {
        $company = $this->company($user->ID);
        return $company !== '' ? $company : $this->full_name($user);
    }

    private function managers()
    {
        $result = [];

        foreach (get_users(['number' => -1]) as $user) {
            if ($this->is_manager($user)) {
                $result[] = $user;
            }
        }

        return $result;
    }

    private function manager_clients($manager_id)
    {
        $manager = get_userdata(absint($manager_id));

        if (!$manager instanceof WP_User) {
            return [];
        }

        $admin   = user_can($manager, 'manage_options');
        $clients = [];

        foreach (
            get_users([
                'number'  => -1,
                'orderby' => 'display_name',
                'order'   => 'ASC',
            ]) as $candidate
        ) {
            if (!$this->is_client($candidate)) {
                continue;
            }

            if (
                !$admin &&
                absint(get_user_meta(
                    $candidate->ID,
                    self::MANAGER_META,
                    true
                )) !== (int) $manager->ID
            ) {
                continue;
            }

            $clients[] = $candidate;
        }

        usort($clients, function ($a, $b) {
            return strnatcasecmp(
                $this->client_title($a),
                $this->client_title($b)
            );
        });

        return $clients;
    }

    private function can_manage_client($manager_id, $client_id)
    {
        $manager = get_userdata(absint($manager_id));
        $client  = get_userdata(absint($client_id));

        if (
            !$manager instanceof WP_User ||
            !$client instanceof WP_User ||
            !$this->is_client($client)
        ) {
            return false;
        }

        if (user_can($manager, 'manage_options')) {
            return true;
        }

        return absint(get_user_meta(
            $client_id,
            self::MANAGER_META,
            true
        )) === (int) $manager_id;
    }

    private function rate($value)
    {
        return min(
            100,
            max(
                0,
                round(
                    (float) str_replace(',', '.', (string) $value),
                    4
                )
            )
        );
    }

    private function posted_number($key)
    {
        if (!isset($_POST[$key])) {
            return 0;
        }

        return (float) str_replace(
            ',',
            '.',
            wp_unslash($_POST[$key])
        );
    }

    private function discount($user_id)
    {
        $discount = get_user_meta(
            $user_id,
            self::DISCOUNT_META,
            true
        );

        if ($discount === '') {
            $discount = get_user_meta(
                $user_id,
                self::OLD_RATE_META,
                true
            );
        }

        return $this->rate($discount);
    }

    private function referral_rate($user_id)
    {
        return $this->rate(get_user_meta(
            $user_id,
            self::REFERRAL_RATE_META,
            true
        ));
    }

    private function allow_payment($user_id)
    {
        return get_user_meta(
            $user_id,
            self::ALLOW_PAYMENT_META,
            true
        ) !== 'no';
    }

    private function max_payment($user_id)
    {
        $value = get_user_meta(
            $user_id,
            self::MAX_PAYMENT_META,
            true
        );

        return $value === '' ? 100 : $this->rate($value);
    }

    private function min_payment($user_id)
    {
        $value = get_user_meta(
            $user_id,
            self::MIN_PAYMENT_META,
            true
        );

        return $value === '' ? 1 : max(0, (float) $value);
    }

    private function min_withdrawal($user_id)
    {
        $value = get_user_meta(
            $user_id,
            self::MIN_WITHDRAW_META,
            true
        );

        return $value === '' ? 1000 : max(0, (float) $value);
    }

    public function migrate_users()
    {
        if (get_option('ccb_v22_users_migrated') === self::VERSION) {
            return;
        }

        foreach (
            get_users([
                'number' => -1,
                'fields' => 'ids',
            ]) as $user_id
        ) {
            $company = $this->company($user_id);

            if ($company !== '') {
                update_user_meta($user_id, self::COMPANY_META, $company);
                update_user_meta($user_id, '_cc_company_name', $company);
                update_user_meta($user_id, 'billing_company', $company);
            }

            $bin = trim((string) $this->meta_value(
                $user_id,
                [
                    self::BIN_META,
                    '_cc_company_bin',
                    'billing_company_id',
                ]
            ));

            if ($bin !== '') {
                update_user_meta($user_id, self::BIN_META, $bin);
                update_user_meta($user_id, '_cc_company_bin', $bin);
            }

            $discount = $this->discount($user_id);

            update_user_meta(
                $user_id,
                self::DISCOUNT_META,
                $discount
            );

            update_user_meta(
                $user_id,
                self::OLD_RATE_META,
                $discount
            );

            if (
                get_user_meta(
                    $user_id,
                    self::ALLOW_PAYMENT_META,
                    true
                ) === ''
            ) {
                update_user_meta(
                    $user_id,
                    self::ALLOW_PAYMENT_META,
                    'yes'
                );
            }

            if (
                get_user_meta(
                    $user_id,
                    self::MAX_PAYMENT_META,
                    true
                ) === ''
            ) {
                update_user_meta(
                    $user_id,
                    self::MAX_PAYMENT_META,
                    100
                );
            }

            if (
                get_user_meta(
                    $user_id,
                    self::MIN_PAYMENT_META,
                    true
                ) === ''
            ) {
                update_user_meta(
                    $user_id,
                    self::MIN_PAYMENT_META,
                    1
                );
            }

            if (
                get_user_meta(
                    $user_id,
                    self::MIN_WITHDRAW_META,
                    true
                ) === ''
            ) {
                update_user_meta(
                    $user_id,
                    self::MIN_WITHDRAW_META,
                    1000
                );
            }

            $this->migrate_opening_balance($user_id);
        }

        update_option('ccb_v22_users_migrated', self::VERSION);
    }

    private function migrate_opening_balance($user_id)
    {
        if (get_user_meta(
            $user_id,
            self::MIGRATED_META,
            true
        )) {
            return;
        }

        $legacy  = max(0, (float) get_user_meta(
            $user_id,
            '_cc_bonus_balance',
            true
        ));

        $current = $this->balance($user_id);
        $delta   = round($legacy - $current, 4);

        if ($delta > 0) {
            $this->add_entry(
                $user_id,
                0,
                0,
                'opening_balance',
                $delta,
                0,
                'Перенос старого бонусного баланса',
                'opening_balance:v22:user:' . $user_id
            );
        }

        update_user_meta(
            $user_id,
            self::MIGRATED_META,
            1
        );
    }

    private function add_entry(
        $user_id,
        $source_user_id,
        $order_id,
        $type,
        $amount,
        $rate,
        $note,
        $key
    ) {
        global $wpdb;

        $amount = round((float) $amount, 4);

        if (!$user_id || !$amount || !$key) {
            return false;
        }

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$this->ledger_table()}
                (user_id,source_user_id,order_id,entry_type,
                 amount,rate,note,entry_key,created_at)
                VALUES (%d,%d,%d,%s,%f,%f,%s,%s,%s)",
                absint($user_id),
                absint($source_user_id),
                absint($order_id),
                sanitize_key($type),
                $amount,
                (float) $rate,
                sanitize_text_field($note),
                sanitize_text_field($key),
                current_time('mysql', true)
            )
        );

        if ($result === 1) {
            $this->sync_balance($user_id);
            return true;
        }

        return false;
    }

    private function balance($user_id)
    {
        global $wpdb;

        return max(
            0,
            (float) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(amount),0)
                     FROM {$this->ledger_table()}
                     WHERE user_id=%d",
                    absint($user_id)
                )
            )
        );
    }

    private function reserved($user_id)
    {
        global $wpdb;

        return max(
            0,
            (float) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(amount),0)
                     FROM {$this->withdrawals_table()}
                     WHERE user_id=%d
                     AND status IN ('pending','approved')",
                    absint($user_id)
                )
            )
        );
    }

    private function total_balance($user_id)
    {
        return $this->balance($user_id) + $this->reserved($user_id);
    }

    private function sync_balance($user_id)
    {
        update_user_meta(
            $user_id,
            '_cc_bonus_balance',
            $this->balance($user_id)
        );
    }

    private function history($user_id, $limit = 100)
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->ledger_table()}
                 WHERE user_id=%d
                 ORDER BY id DESC
                 LIMIT %d",
                absint($user_id),
                absint($limit)
            )
        );
    }

    private function entry_label($type)
    {
        $labels = [
            'opening_balance'    => 'Начальный баланс',
            'referral'           => 'Реферальное начисление',
            'referral_reversal'  => 'Возврат реферального бонуса',
            'order_payment'      => 'Оплата заказа бонусами',
            'order_refund'       => 'Возврат бонусов по заказу',
            'withdrawal_hold'    => 'Резерв на вывод',
            'withdrawal_release' => 'Возврат резерва',
            'manual_credit'      => 'Ручное начисление',
            'manual_debit'       => 'Ручное списание',
        ];

        return $labels[$type] ?? 'Бонусная операция';
    }

    public function handle_completed_order($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        $customer_id = absint($order->get_customer_id());

        if (!$customer_id) {
            return;
        }

        /*
         * Собственный процент за покупку не начисляется.
         * Он является персональной скидкой.
         */
        $referrer_id = absint(get_user_meta(
            $customer_id,
            self::REFERRER_META,
            true
        ));

        if (!$referrer_id || $referrer_id === $customer_id) {
            return;
        }

        $rate = $this->referral_rate($referrer_id);

        if ($rate <= 0) {
            return;
        }

        $base = 0;

        foreach ($order->get_items('line_item') as $item) {
            $base +=
                (float) $item->get_total() +
                (float) $item->get_total_tax();
        }

        $amount = round(
            $base * $rate / 100,
            function_exists('wc_get_price_decimals')
                ? wc_get_price_decimals()
                : 2
        );

        if ($amount <= 0) {
            return;
        }

        $customer = get_userdata($customer_id);

        $this->add_entry(
            $referrer_id,
            $customer_id,
            $order_id,
            'referral',
            $amount,
            $rate,
            'За заказ #' . $order->get_order_number() .
            (
                $customer instanceof WP_User
                    ? ' клиента ' . $this->full_name($customer)
                    : ''
            ),
            'referral:order:' . $order_id
        );
    }

    public function handle_order_status_changed(
        $order_id,
        $old_status,
        $new_status,
        $order
    ) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        if (
            in_array(
                $new_status,
                ['cancelled', 'failed', 'refunded'],
                true
            )
        ) {
            $this->restore_order_bonus($order);
            $this->reverse_referral($order);
        }
    }

    private function reverse_referral($order)
    {
        global $wpdb;

        $entries = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->ledger_table()}
                 WHERE order_id=%d
                 AND entry_type='referral'
                 AND amount>0",
                $order->get_id()
            )
        );

        foreach ($entries as $entry) {
            $this->add_entry(
                $entry->user_id,
                $entry->source_user_id,
                $order->get_id(),
                'referral_reversal',
                -abs((float) $entry->amount),
                $entry->rate,
                'Сторнирование по заказу #' .
                $order->get_order_number(),
                'referral_reversal:entry:' . $entry->id
            );
        }
    }

    public function render_checkout_bonus()
    {
        if (
            !is_user_logged_in() ||
            !$this->is_client(wp_get_current_user())
        ) {
            return;
        }

        $user_id = get_current_user_id();

        if (!$this->allow_payment($user_id)) {
            return;
        }

        $available = $this->balance($user_id);

        if ($available < $this->min_payment($user_id)) {
            return;
        }

        $current = WC()->session
            ? (float) WC()->session->get('ccb_bonus_to_use', 0)
            : 0;
        ?>
        <div class="ccb-checkout-bonus">
            <h3>Оплата бонусами</h3>

            <p>
                Доступно:
                <strong><?php echo wp_kses_post($this->money($available)); ?></strong>
            </p>

            <label>
                <span>Сколько бонусов использовать</span>

                <input
                    type="number"
                    name="ccb_bonus_to_use"
                    min="0"
                    max="<?php echo esc_attr($available); ?>"
                    step="0.01"
                    value="<?php echo esc_attr($current); ?>"
                >
            </label>

            <button
                type="button"
                class="button ccb-apply-checkout-bonus"
            >
                Применить бонусы
            </button>
        </div>
        <?php
    }

    public function update_checkout_bonus($posted_data)
    {
        if (!WC()->session || !is_user_logged_in()) {
            return;
        }

        parse_str($posted_data, $data);

        $amount = isset($data['ccb_bonus_to_use'])
            ? max(
                0,
                (float) str_replace(
                    ',',
                    '.',
                    $data['ccb_bonus_to_use']
                )
            )
            : 0;

        WC()->session->set('ccb_bonus_to_use', $amount);
    }

    public function apply_bonus_to_cart($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (
            !is_user_logged_in() ||
            !WC()->session ||
            !$this->allow_payment(get_current_user_id())
        ) {
            return;
        }

        $requested = (float) WC()->session->get(
            'ccb_bonus_to_use',
            0
        );

        if ($requested <= 0) {
            return;
        }

        $user_id  = get_current_user_id();
        $balance  = $this->balance($user_id);
        $subtotal = max(0, (float) $cart->get_subtotal());
        $maximum  = $subtotal * $this->max_payment($user_id) / 100;

        $amount = round(
            min($requested, $balance, $maximum),
            function_exists('wc_get_price_decimals')
                ? wc_get_price_decimals()
                : 2
        );

        if ($amount < $this->min_payment($user_id)) {
            WC()->session->set('ccb_bonus_to_use', 0);
            return;
        }

        WC()->session->set('ccb_bonus_to_use', $amount);

        $cart->add_fee(
            'Оплата бонусами',
            -$amount,
            false
        );
    }

    public function save_order_bonus_payment(
        $order_id,
        $posted_data,
        $order
    ) {
        if (!is_user_logged_in() || !WC()->session) {
            return;
        }

        $amount = (float) WC()->session->get(
            'ccb_bonus_to_use',
            0
        );

        if ($amount <= 0) {
            return;
        }

        $user_id = get_current_user_id();
        $amount  = min($amount, $this->balance($user_id));

        if ($amount <= 0) {
            return;
        }

        $saved = $this->add_entry(
            $user_id,
            $user_id,
            $order_id,
            'order_payment',
            -$amount,
            0,
            'Оплата заказа #' . $order->get_order_number(),
            'order_payment:order:' . $order_id
        );

        if ($saved) {
            $order->update_meta_data('_ccb_bonus_used', $amount);
            $order->add_order_note(
                'Оплачено бонусами: ' .
                wp_strip_all_tags($this->money($amount))
            );
            $order->save();
        }

        WC()->session->set('ccb_bonus_to_use', 0);
    }

    private function restore_order_bonus($order)
    {
        $amount  = (float) $order->get_meta('_ccb_bonus_used', true);
        $user_id = absint($order->get_customer_id());

        if (!$amount || !$user_id) {
            return;
        }

        $this->add_entry(
            $user_id,
            $user_id,
            $order->get_id(),
            'order_refund',
            abs($amount),
            0,
            'Возврат по заказу #' . $order->get_order_number(),
            'order_bonus_refund:order:' . $order->get_id()
        );
    }

    private function get_withdrawal($id)
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->withdrawals_table()}
                 WHERE id=%d",
                absint($id)
            )
        );
    }

    private function user_withdrawals($user_id)
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->withdrawals_table()}
                 WHERE user_id=%d
                 ORDER BY id DESC",
                absint($user_id)
            )
        );
    }

    private function manager_withdrawals($manager)
    {
        global $wpdb;

        if (user_can($manager, 'manage_options')) {
            return $wpdb->get_results(
                "SELECT *
                 FROM {$this->withdrawals_table()}
                 ORDER BY
                 FIELD(status,'pending','approved','paid','rejected','cancelled'),
                 id DESC"
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->withdrawals_table()}
                 WHERE manager_id=%d
                 ORDER BY
                 FIELD(status,'pending','approved','paid','rejected','cancelled'),
                 id DESC",
                $manager->ID
            )
        );
    }

    private function create_withdrawal(
        $user_id,
        $amount,
        $method,
        $requisites,
        $comment
    ) {
        global $wpdb;

        $amount = round((float) $amount, 2);

        if (
            $amount < $this->min_withdrawal($user_id) ||
            $amount > $this->balance($user_id)
        ) {
            return new WP_Error(
                'invalid_amount',
                'Недостаточно бонусов или сумма меньше минимальной.'
            );
        }

        $manager_id = absint(get_user_meta(
            $user_id,
            self::MANAGER_META,
            true
        ));

        $inserted = $wpdb->insert(
            $this->withdrawals_table(),
            [
                'user_id'         => $user_id,
                'manager_id'      => $manager_id,
                'amount'          => $amount,
                'payment_method'  => sanitize_text_field($method),
                'requisites'      => sanitize_textarea_field($requisites),
                'client_comment'  => sanitize_textarea_field($comment),
                'manager_comment' => '',
                'status'          => 'pending',
                'created_at'      => current_time('mysql', true),
                'updated_at'      => current_time('mysql', true),
            ],
            ['%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$inserted) {
            return new WP_Error(
                'database_error',
                'Не удалось создать запрос.'
            );
        }

        $request_id = absint($wpdb->insert_id);

        $held = $this->add_entry(
            $user_id,
            0,
            0,
            'withdrawal_hold',
            -$amount,
            0,
            'Резерв по запросу на вывод #' . $request_id,
            'withdrawal_hold:' . $request_id
        );

        if (!$held) {
            $wpdb->delete(
                $this->withdrawals_table(),
                ['id' => $request_id],
                ['%d']
            );

            return new WP_Error(
                'balance_error',
                'Не удалось зарезервировать бонусы.'
            );
        }

        $manager = get_userdata($manager_id);
        $client  = get_userdata($user_id);

        if (
            $manager instanceof WP_User &&
            $manager->user_email
        ) {
            wp_mail(
                $manager->user_email,
                'Новый запрос на вывод бонусов',
                sprintf(
                    "Контрагент: %s\nСумма: %s",
                    $client instanceof WP_User
                        ? $this->client_title($client)
                        : 'Клиент #' . $user_id,
                    wp_strip_all_tags($this->money($amount))
                )
            );
        }

        return $request_id;
    }

    private function change_withdrawal_status(
        $request,
        $status,
        $comment
    ) {
        global $wpdb;

        $transitions = [
            'pending'   => ['approved', 'rejected', 'cancelled'],
            'approved'  => ['paid', 'rejected'],
            'paid'      => [],
            'rejected'  => [],
            'cancelled' => [],
        ];

        if (
            empty($transitions[$request->status]) ||
            !in_array(
                $status,
                $transitions[$request->status],
                true
            )
        ) {
            return false;
        }

        $updated = $wpdb->update(
            $this->withdrawals_table(),
            [
                'status'          => $status,
                'manager_comment' => sanitize_textarea_field($comment),
                'updated_at'      => current_time('mysql', true),
            ],
            ['id' => $request->id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return false;
        }

        if (in_array($status, ['rejected', 'cancelled'], true)) {
            $this->add_entry(
                $request->user_id,
                0,
                0,
                'withdrawal_release',
                abs((float) $request->amount),
                0,
                'Возврат по запросу на вывод #' . $request->id,
                'withdrawal_release:' . $request->id
            );
        }

        return true;
    }

    private function withdrawal_label($status)
    {
        $labels = [
            'pending'   => 'На рассмотрении',
            'approved'  => 'Одобрено',
            'paid'      => 'Выплачено',
            'rejected'  => 'Отклонено',
            'cancelled' => 'Отменено',
        ];

        return $labels[$status] ?? $status;
    }

    public function handle_frontend_actions()
    {
        if (
            !is_user_logged_in() ||
            empty($_POST['ccb_action'])
        ) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['ccb_action']));

        if ($action === 'save_bonus_settings') {
            $this->save_bonus_settings();
        }

        if ($action === 'create_withdrawal') {
            $this->handle_create_withdrawal();
        }

        if ($action === 'change_withdrawal_status') {
            $this->handle_change_withdrawal_status();
        }

        if ($action === 'cancel_withdrawal') {
            $this->handle_cancel_withdrawal();
        }
    }

    private function save_bonus_settings()
    {
        $manager = wp_get_current_user();

        if (!$this->is_manager($manager)) {
            wp_die('Недостаточно прав.');
        }

        check_admin_referer(
            'ccb_save_bonus_settings',
            'ccb_nonce'
        );

        $client_id = absint($_POST['client_id'] ?? 0);

        if (!$this->can_manage_client($manager->ID, $client_id)) {
            wp_die('Нет доступа к контрагенту.');
        }

        $discount = $this->rate(
            $this->posted_number('discount')
        );

        update_user_meta(
            $client_id,
            self::DISCOUNT_META,
            $discount
        );

        update_user_meta(
            $client_id,
            self::OLD_RATE_META,
            $discount
        );

        update_user_meta(
            $client_id,
            self::REFERRAL_RATE_META,
            $this->rate($this->posted_number('referral_rate'))
        );

        update_user_meta(
            $client_id,
            self::MAX_PAYMENT_META,
            $this->rate($this->posted_number('max_payment'))
        );

        update_user_meta(
            $client_id,
            self::MIN_PAYMENT_META,
            max(0, $this->posted_number('min_payment'))
        );

        update_user_meta(
            $client_id,
            self::MIN_WITHDRAW_META,
            max(0, $this->posted_number('min_withdrawal'))
        );

        update_user_meta(
            $client_id,
            self::ALLOW_PAYMENT_META,
            !empty($_POST['allow_payment']) ? 'yes' : 'no'
        );

        $referrer_id = absint($_POST['referrer_id'] ?? 0);

        if (
            $referrer_id &&
            $referrer_id !== $client_id &&
            $this->can_manage_client($manager->ID, $referrer_id)
        ) {
            update_user_meta(
                $client_id,
                self::REFERRER_META,
                $referrer_id
            );
        } else {
            delete_user_meta(
                $client_id,
                self::REFERRER_META
            );
        }

        wp_safe_redirect(add_query_arg(
            [
                'cc_section' => 'bonuses',
                'ccb_saved'  => $client_id,
            ],
            get_permalink()
        ));

        exit;
    }

    private function handle_create_withdrawal()
    {
        $user = wp_get_current_user();

        if (!$this->is_client($user)) {
            wp_die('Недостаточно прав.');
        }

        check_admin_referer(
            'ccb_create_withdrawal',
            'ccb_nonce'
        );

        $result = $this->create_withdrawal(
            $user->ID,
            $this->posted_number('amount'),
            wp_unslash($_POST['payment_method'] ?? ''),
            wp_unslash($_POST['requisites'] ?? ''),
            wp_unslash($_POST['comment'] ?? '')
        );

        $args = is_wp_error($result)
            ? [
                'cc_section' => 'bonuses',
                'ccb_error'  => $result->get_error_message(),
            ]
            : [
                'cc_section' => 'bonuses',
                'ccb_notice' => 'withdrawal_created',
            ];

        wp_safe_redirect(add_query_arg($args, get_permalink()));
        exit;
    }

    private function handle_change_withdrawal_status()
    {
        $manager = wp_get_current_user();

        if (!$this->is_manager($manager)) {
            wp_die('Недостаточно прав.');
        }

        check_admin_referer(
            'ccb_change_withdrawal_status',
            'ccb_nonce'
        );

        $request = $this->get_withdrawal(
            absint($_POST['request_id'] ?? 0)
        );

        if (
            !$request ||
            !$this->can_manage_client(
                $manager->ID,
                $request->user_id
            )
        ) {
            wp_die('Нет доступа к запросу.');
        }

        $this->change_withdrawal_status(
            $request,
            sanitize_key(wp_unslash(
                $_POST['new_status'] ?? ''
            )),
            sanitize_textarea_field(wp_unslash(
                $_POST['manager_comment'] ?? ''
            ))
        );

        wp_safe_redirect(add_query_arg(
            [
                'cc_section' => 'bonuses',
                'ccb_notice' => 'status_updated',
            ],
            get_permalink()
        ));

        exit;
    }

    private function handle_cancel_withdrawal()
    {
        $user = wp_get_current_user();

        check_admin_referer(
            'ccb_cancel_withdrawal',
            'ccb_nonce'
        );

        $request = $this->get_withdrawal(
            absint($_POST['request_id'] ?? 0)
        );

        if (
            !$request ||
            (int) $request->user_id !== (int) $user->ID ||
            $request->status !== 'pending'
        ) {
            wp_die('Запрос нельзя отменить.');
        }

        $this->change_withdrawal_status(
            $request,
            'cancelled',
            'Отменено закупщиком'
        );

        wp_safe_redirect(add_query_arg(
            [
                'cc_section' => 'bonuses',
                'ccb_notice' => 'withdrawal_cancelled',
            ],
            get_permalink()
        ));

        exit;
    }

    private function referral_code($user_id)
    {
        $code = sanitize_key(get_user_meta(
            $user_id,
            self::REFERRAL_CODE_META,
            true
        ));

        if ($code !== '') {
            return $code;
        }

        $code = 'b2b' .
            absint($user_id) .
            strtolower(wp_generate_password(8, false, false));

        update_user_meta(
            $user_id,
            self::REFERRAL_CODE_META,
            $code
        );

        return $code;
    }

    private function referral_link($user_id)
    {
        return add_query_arg(
            ['ccb_ref' => $this->referral_code($user_id)],
            home_url('/')
        );
    }

    private function user_by_referral_code($code)
    {
        $users = get_users([
            'number'     => 1,
            'meta_key'   => self::REFERRAL_CODE_META,
            'meta_value' => sanitize_key($code),
        ]);

        return !empty($users) ? $users[0] : false;
    }

    public function capture_referral_code()
    {
        if (empty($_GET['ccb_ref'])) {
            return;
        }

        $code = sanitize_key(wp_unslash($_GET['ccb_ref']));

        if (!$this->user_by_referral_code($code)) {
            return;
        }

        setcookie(
            self::REFERRAL_COOKIE,
            $code,
            [
                'expires'  => time() + 30 * DAY_IN_SECONDS,
                'path'     => '/',
                'domain'   => defined('COOKIE_DOMAIN')
                    ? COOKIE_DOMAIN
                    : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        $_COOKIE[self::REFERRAL_COOKIE] = $code;
    }

    public function handle_new_user($user_id)
    {
        $this->referral_code($user_id);

        if (empty($_COOKIE[self::REFERRAL_COOKIE])) {
            return;
        }

        $referrer = $this->user_by_referral_code(
            wp_unslash($_COOKIE[self::REFERRAL_COOKIE])
        );

        if (
            $referrer instanceof WP_User &&
            (int) $referrer->ID !== (int) $user_id
        ) {
            update_user_meta(
                $user_id,
                self::REFERRER_META,
                $referrer->ID
            );
        }
    }

    private function user_orders($user, $limit = 100)
    {
        if (
            !$user instanceof WP_User ||
            !function_exists('wc_get_orders')
        ) {
            return [];
        }

        $orders = [];

        foreach (
            wc_get_orders([
                'customer_id' => $user->ID,
                'limit'       => $limit,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]) as $order
        ) {
            $orders[$order->get_id()] = $order;
        }

        if ($user->user_email) {
            foreach (
                wc_get_orders([
                    'billing_email' => $user->user_email,
                    'limit'         => $limit,
                    'orderby'       => 'date',
                    'order'         => 'DESC',
                ]) as $order
            ) {
                $orders[$order->get_id()] = $order;
            }
        }

        uasort($orders, function ($a, $b) {
            $ad = $a->get_date_created();
            $bd = $b->get_date_created();

            return ($bd ? $bd->getTimestamp() : 0)
                <=>
                ($ad ? $ad->getTimestamp() : 0);
        });

        return array_slice(array_values($orders), 0, $limit);
    }

    private function manager_orders($clients, $limit = 500)
    {
        $orders = [];

        foreach ($clients as $client) {
            foreach ($this->user_orders($client, 200) as $order) {
                $orders[$order->get_id()] = $order;
            }
        }

        uasort($orders, function ($a, $b) {
            $ad = $a->get_date_created();
            $bd = $b->get_date_created();

            return ($bd ? $bd->getTimestamp() : 0)
                <=>
                ($ad ? $ad->getTimestamp() : 0);
        });

        return array_slice(array_values($orders), 0, $limit);
    }

    private function money($amount)
    {
        return function_exists('wc_price')
            ? wc_price((float) $amount)
            : number_format((float) $amount, 2, ',', ' ') . ' ₸';
    }

    private function section_url($section, array $extra = [])
    {
        $url = remove_query_arg(
            [
                'cc_section',
                'ccb_saved',
                'ccb_notice',
                'ccb_error',
                'ccb_search',
                'ccb_period',
                'cc_view',
                'cc_client_id',
                'cc_client_search',
                'cc_status_updated',
            ],
            get_permalink()
        );

        return add_query_arg(
            array_merge(['cc_section' => $section], $extra),
            $url
        );
    }

    public function integrate_into_cabinet(
        $output,
        $tag,
        $attr,
        $match
    ) {
        if (
            $tag !== 'cc_b2b_cabinet' ||
            !is_user_logged_in()
        ) {
            return $output;
        }

        $user = wp_get_current_user();

        if (
            !$this->is_manager($user) &&
            !$this->is_client($user)
        ) {
            return $output;
        }

        $section = isset($_GET['cc_section'])
            ? sanitize_key(wp_unslash($_GET['cc_section']))
            : 'dashboard';

        if (
            !empty($_GET['cc_view']) ||
            !empty($_GET['cc_client_id']) ||
            !empty($_GET['cc_client_search']) ||
            !empty($_GET['cc_status_updated'])
        ) {
            $section = 'cabinet';
        }

        if (
            !in_array(
                $section,
                ['dashboard', 'cabinet', 'bonuses'],
                true
            )
        ) {
            $section = 'dashboard';
        }

        ob_start();

        echo '<div class="ccb-integrated-cabinet">';

        $this->navigation($section);

        if ($section === 'cabinet') {
            echo '<div class="ccb-original-cabinet">';
            echo $output;
            echo '</div>';
        } elseif ($section === 'bonuses') {
            echo $this->bonus_shortcode();
        } elseif ($this->is_manager($user)) {
            $this->manager_dashboard($user);
        } else {
            $this->client_dashboard($user);
        }

        echo '</div>';

        return ob_get_clean();
    }

    private function navigation($active)
    {
        ?>
        <nav class="ccb-main-nav">
            <a
                class="<?php echo $active === 'dashboard'
                    ? 'is-active'
                    : ''; ?>"
                href="<?php echo esc_url(
                    $this->section_url('dashboard')
                ); ?>"
            >
                Главная
            </a>

            <a
                class="<?php echo $active === 'cabinet'
                    ? 'is-active'
                    : ''; ?>"
                href="<?php echo esc_url(
                    $this->section_url('cabinet')
                ); ?>"
            >
                Контрагенты и заказы
            </a>

            <a
                class="<?php echo $active === 'bonuses'
                    ? 'is-active'
                    : ''; ?>"
                href="<?php echo esc_url(
                    $this->section_url('bonuses')
                ); ?>"
            >
                Бонусы и вывод
            </a>
        </nav>
        <?php
    }

    private function manager_dashboard($manager)
    {
        $clients     = $this->manager_clients($manager->ID);
        $orders      = $this->manager_orders($clients);
        $withdrawals = $this->manager_withdrawals($manager);

        $sales         = 0;
        $active_orders = 0;
        $pending_sum   = 0;
        $refunds       = 0;
        $pending_out   = 0;
        $reserved_out  = 0;
        $total_bonus   = 0;

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            if (
                !in_array(
                    $order->get_status(),
                    ['cancelled', 'failed', 'refunded', 'trash'],
                    true
                )
            ) {
                $sales += (float) $order->get_total();
            }

            if (
                in_array(
                    $order->get_status(),
                    ['pending', 'processing', 'on-hold'],
                    true
                )
            ) {
                $active_orders++;
            }

            if (
                in_array(
                    $order->get_status(),
                    ['pending', 'on-hold'],
                    true
                )
            ) {
                $pending_sum += (float) $order->get_total();
            }

            $refunds += abs((float) $order->get_total_refunded());
        }

        foreach ($clients as $client) {
            $total_bonus += $this->balance($client->ID);
        }

        foreach ($withdrawals as $request) {
            if ($request->status === 'pending') {
                $pending_out++;
            }

            if (
                in_array(
                    $request->status,
                    ['pending', 'approved'],
                    true
                )
            ) {
                $reserved_out += (float) $request->amount;
            }
        }

        $average = count($orders)
            ? $sales / count($orders)
            : 0;
        ?>
        <section class="ccb-dashboard">
            <div class="ccb-hero">
                <div>
                    <small>Аналитика менеджера</small>

                    <h1>
                        Добрый день,
                        <?php echo esc_html(
                            $this->full_name($manager)
                        ); ?>
                    </h1>

                    <p>
                        Продажи, контрагенты, заказы, бонусы и выводы.
                    </p>
                </div>

                <a
                    class="ccb-hero-button"
                    href="<?php echo esc_url(
                        $this->section_url('cabinet')
                    ); ?>"
                >
                    Найти контрагента
                </a>
            </div>

            <div class="ccb-kpi-grid">
                <?php
                $this->kpi('Контрагенты', count($clients));
                $this->kpi('Всего заказов', count($orders));
                $this->kpi('Активные заказы', $active_orders);
                $this->kpi('Продажи', $this->money($sales), true);
                $this->kpi('Средний чек', $this->money($average), true);
                $this->kpi('Ожидает оплаты', $this->money($pending_sum), true);
                $this->kpi('Возвраты', $this->money($refunds), true);
                $this->kpi('Бонусный баланс', $this->money($total_bonus), true);
                $this->kpi('Запросы на вывод', $pending_out);
                $this->kpi('В резерве', $this->money($reserved_out), true);
                ?>
            </div>

            <div class="ccb-two-columns">
                <div class="ccb-panel">
                    <div class="ccb-panel-heading">
                        <h2>Последние заказы</h2>

                        <a href="<?php echo esc_url(
                            $this->section_url('cabinet')
                        ); ?>">
                            Все заказы
                        </a>
                    </div>

                    <?php
                    $this->render_orders(
                        array_slice($orders, 0, 8),
                        true
                    );
                    ?>
                </div>

                <div class="ccb-panel">
                    <div class="ccb-panel-heading">
                        <h2>Запросы на вывод</h2>

                        <a href="<?php echo esc_url(
                            $this->section_url('bonuses')
                        ); ?>">
                            Управление
                        </a>
                    </div>

                    <?php
                    $this->render_manager_withdrawals(
                        array_slice($withdrawals, 0, 6)
                    );
                    ?>
                </div>
            </div>
        </section>
        <?php
    }

    private function client_dashboard($client)
    {
        $orders = $this->user_orders($client, 100);

        $active_orders = 0;
        $orders_total  = 0;

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            if (
                in_array(
                    $order->get_status(),
                    ['pending', 'processing', 'on-hold'],
                    true
                )
            ) {
                $active_orders++;
            }

            if (
                !in_array(
                    $order->get_status(),
                    ['cancelled', 'failed', 'refunded', 'trash'],
                    true
                )
            ) {
                $orders_total += (float) $order->get_total();
            }
        }

        $credit_limit = (float) $this->meta_value(
            $client->ID,
            ['_cc_b2b_credit_limit', '_cc_credit_limit']
        );

        $credit_used = (float) $this->meta_value(
            $client->ID,
            ['_cc_b2b_credit_used', '_cc_credit_used']
        );
        ?>
        <section class="ccb-dashboard">
            <div class="ccb-hero">
                <div>
                    <small>Кабинет закупщика</small>

                    <h1>
                        <?php echo esc_html(
                            $this->client_title($client)
                        ); ?>
                    </h1>

                    <p>
                        Заказы, персональная скидка и бонусный баланс.
                    </p>
                </div>

                <div class="ccb-hero-balance">
                    <span>Доступно бонусов</span>

                    <strong>
                        <?php echo wp_kses_post(
                            $this->money(
                                $this->balance($client->ID)
                            )
                        ); ?>
                    </strong>
                </div>
            </div>

            <div class="ccb-kpi-grid">
                <?php
                $this->kpi(
                    'Персональная скидка',
                    $this->discount($client->ID) . '%'
                );

                $this->kpi(
                    'Реферальный процент',
                    $this->referral_rate($client->ID) . '%'
                );

                $this->kpi(
                    'Активные заказы',
                    $active_orders
                );

                $this->kpi(
                    'Сумма заказов',
                    $this->money($orders_total),
                    true
                );

                $this->kpi(
                    'Кредитный лимит',
                    $this->money($credit_limit),
                    true
                );

                $this->kpi(
                    'Использовано кредита',
                    $this->money($credit_used),
                    true
                );

                $this->kpi(
                    'Зарезервировано',
                    $this->money(
                        $this->reserved($client->ID)
                    ),
                    true
                );
                ?>
            </div>

            <div class="ccb-two-columns">
                <div class="ccb-panel">
                    <div class="ccb-panel-heading">
                        <h2>Реферальная ссылка</h2>
                    </div>

                    <div class="ccb-copy-row">
                        <input
                            type="text"
                            readonly
                            value="<?php echo esc_attr(
                                $this->referral_link($client->ID)
                            ); ?>"
                        >

                        <button
                            type="button"
                            data-ccb-copy="<?php echo esc_attr(
                                $this->referral_link($client->ID)
                            ); ?>"
                        >
                            Копировать
                        </button>
                    </div>
                </div>

                <div class="ccb-panel">
                    <div class="ccb-panel-heading">
                        <h2>Быстрые действия</h2>
                    </div>

                    <div class="ccb-actions">
                        <a href="<?php echo esc_url(
                            $this->section_url('bonuses')
                        ); ?>">
                            Запросить вывод
                        </a>

                        <a href="<?php echo esc_url(
                            function_exists('wc_get_cart_url')
                                ? wc_get_cart_url()
                                : home_url('/')
                        ); ?>">
                            Оплатить бонусами
                        </a>
                    </div>
                </div>
            </div>

            <div class="ccb-panel">
                <div class="ccb-panel-heading">
                    <h2>Последние заказы</h2>

                    <a href="<?php echo esc_url(
                        $this->section_url('cabinet')
                    ); ?>">
                        Все заказы
                    </a>
                </div>

                <?php
                $this->render_orders(
                    array_slice($orders, 0, 8),
                    false
                );
                ?>
            </div>
        </section>
        <?php
    }

    private function kpi($label, $value, $html = false)
    {
        ?>
        <div class="ccb-kpi">
            <span><?php echo esc_html($label); ?></span>

            <strong>
                <?php
                echo $html
                    ? wp_kses_post($value)
                    : esc_html($value);
                ?>
            </strong>
        </div>
        <?php
    }

    private function render_orders($orders, $show_customer)
    {
        if (empty($orders)) {
            echo '<div class="ccb-empty">Заказов пока нет.</div>';
            return;
        }

        echo '<div class="ccb-order-list">';

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            $date     = $order->get_date_created();
            $customer = $order->get_user();

            echo '<div class="ccb-order-row">';

            echo '<div><small>Заказ</small><strong>#' .
                esc_html($order->get_order_number()) .
                '</strong></div>';

            if ($show_customer) {
                echo '<div><small>Контрагент</small><strong>' .
                    esc_html(
                        $customer instanceof WP_User
                            ? $this->client_title($customer)
                            : (
                                $order->get_billing_company()
                                ?: $order->get_formatted_billing_full_name()
                            )
                    ) .
                    '</strong></div>';
            }

            echo '<div><small>Дата</small><strong>' .
                esc_html(
                    $date
                        ? $date->date_i18n('d.m.Y')
                        : '—'
                ) .
                '</strong></div>';

            echo '<span class="ccb-status">' .
                esc_html(wc_get_order_status_name(
                    $order->get_status()
                )) .
                '</span>';

            echo '<b>' .
                wp_kses_post(
                    $order->get_formatted_order_total()
                ) .
                '</b>';

            echo '</div>';
        }

        echo '</div>';
    }

    public function bonus_shortcode()
    {
        if (!is_user_logged_in()) {
            return '<div class="ccb-empty">Войдите в кабинет.</div>';
        }

        $user = wp_get_current_user();

        ob_start();

        echo '<div class="ccb-bonus-section">';

        if (!empty($_GET['ccb_error'])) {
            echo '<div class="ccb-message error">' .
                esc_html(wp_unslash($_GET['ccb_error'])) .
                '</div>';
        }

        if (!empty($_GET['ccb_notice'])) {
            echo '<div class="ccb-message success">Операция выполнена.</div>';
        }

        if ($this->is_manager($user)) {
            $this->manager_bonus_page($user);
        } elseif ($this->is_client($user)) {
            $this->client_bonus_page($user);
        }

        echo '</div>';

        return ob_get_clean();
    }

    private function manager_bonus_page($manager)
    {
        $clients = $this->manager_clients($manager->ID);
        $search  = isset($_GET['ccb_search'])
            ? sanitize_text_field(wp_unslash($_GET['ccb_search']))
            : '';

        if ($search !== '') {
            $clients = array_values(array_filter(
                $clients,
                function ($client) use ($search) {
                    return $this->contains(
                        $this->company($client->ID) . ' ' .
                        $this->full_name($client) . ' ' .
                        $client->user_email,
                        $search
                    );
                }
            ));
        }
        ?>
        <div class="ccb-page-heading">
            <small>Программа лояльности</small>
            <h1>Бонусы и вывод средств</h1>
            <p>
                Скидки, рефералы, оплата бонусами и запросы на вывод.
            </p>
        </div>

        <div class="ccb-panel">
            <div class="ccb-panel-heading">
                <h2>Запросы на вывод</h2>
            </div>

            <?php
            $this->render_manager_withdrawals(
                $this->manager_withdrawals($manager)
            );
            ?>
        </div>

        <div class="ccb-panel">
            <div class="ccb-panel-heading">
                <h2>Настройки контрагентов</h2>
            </div>

            <form method="get" class="ccb-search">
                <input
                    type="hidden"
                    name="cc_section"
                    value="bonuses"
                >

                <input
                    type="search"
                    name="ccb_search"
                    value="<?php echo esc_attr($search); ?>"
                    placeholder="Компания, имя, фамилия или email"
                >

                <button type="submit">Найти</button>
            </form>

            <?php foreach ($clients as $client) : ?>
                <?php
                $referrer_id = absint(get_user_meta(
                    $client->ID,
                    self::REFERRER_META,
                    true
                ));
                ?>

                <details
                    id="ccb-client-<?php echo esc_attr($client->ID); ?>"
                    class="ccb-client-settings"
                    <?php
                    echo (
                        !empty($_GET['ccb_saved']) &&
                        absint($_GET['ccb_saved']) ===
                        (int) $client->ID
                    ) ? 'open' : '';
                    ?>
                >
                    <summary>
                        <div>
                            <strong>
                                <?php echo esc_html(
                                    $this->client_title($client)
                                ); ?>
                            </strong>

                            <small>
                                <?php echo esc_html(
                                    $this->full_name($client)
                                ); ?>
                            </small>
                        </div>

                        <span>
                            Скидка:
                            <b><?php echo esc_html(
                                $this->discount($client->ID)
                            ); ?>%</b>
                        </span>

                        <span>
                            Реферальный:
                            <b><?php echo esc_html(
                                $this->referral_rate($client->ID)
                            ); ?>%</b>
                        </span>

                        <span>
                            Баланс:
                            <b><?php echo wp_kses_post(
                                $this->money(
                                    $this->balance($client->ID)
                                )
                            ); ?></b>
                        </span>
                    </summary>

                    <div class="ccb-client-settings-body">
                        <form method="post" class="ccb-settings-form">
                            <?php
                            wp_nonce_field(
                                'ccb_save_bonus_settings',
                                'ccb_nonce'
                            );
                            ?>

                            <input
                                type="hidden"
                                name="ccb_action"
                                value="save_bonus_settings"
                            >

                            <input
                                type="hidden"
                                name="client_id"
                                value="<?php echo esc_attr(
                                    $client->ID
                                ); ?>"
                            >

                            <label>
                                <span>Персональная скидка / бонус, %</span>
                                <input
                                    type="number"
                                    name="discount"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value="<?php echo esc_attr(
                                        $this->discount($client->ID)
                                    ); ?>"
                                >
                            </label>

                            <label>
                                <span>Реферальный процент, %</span>
                                <input
                                    type="number"
                                    name="referral_rate"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value="<?php echo esc_attr(
                                        $this->referral_rate($client->ID)
                                    ); ?>"
                                >
                            </label>

                            <label>
                                <span>Максимальная оплата бонусами, %</span>
                                <input
                                    type="number"
                                    name="max_payment"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value="<?php echo esc_attr(
                                        $this->max_payment($client->ID)
                                    ); ?>"
                                >
                            </label>

                            <label>
                                <span>Минимальная оплата бонусами</span>
                                <input
                                    type="number"
                                    name="min_payment"
                                    min="0"
                                    step="0.01"
                                    value="<?php echo esc_attr(
                                        $this->min_payment($client->ID)
                                    ); ?>"
                                >
                            </label>

                            <label>
                                <span>Минимальная сумма вывода</span>
                                <input
                                    type="number"
                                    name="min_withdrawal"
                                    min="0"
                                    step="0.01"
                                    value="<?php echo esc_attr(
                                        $this->min_withdrawal($client->ID)
                                    ); ?>"
                                >
                            </label>

                            <label>
                                <span>Кто пригласил</span>

                                <select name="referrer_id">
                                    <option value="0">Никто</option>

                                    <?php
                                    foreach (
                                        $this->manager_clients($manager->ID)
                                        as $referrer
                                    ) :
                                        if (
                                            (int) $referrer->ID ===
                                            (int) $client->ID
                                        ) {
                                            continue;
                                        }
                                        ?>
                                        <option
                                            value="<?php echo esc_attr(
                                                $referrer->ID
                                            ); ?>"
                                            <?php selected(
                                                $referrer_id,
                                                $referrer->ID
                                            ); ?>
                                        >
                                            <?php echo esc_html(
                                                $this->client_title(
                                                    $referrer
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label class="ccb-checkbox">
                                <input
                                    type="checkbox"
                                    name="allow_payment"
                                    value="yes"
                                    <?php checked(
                                        $this->allow_payment($client->ID)
                                    ); ?>
                                >
                                Разрешить оплату бонусами
                            </label>

                            <button type="submit">
                                Сохранить настройки
                            </button>
                        </form>

                        <h3>История операций</h3>

                        <?php
                        $this->render_history(
                            $this->history($client->ID, 30)
                        );
                        ?>
                    </div>
                </details>
            <?php endforeach; ?>

            <?php if (empty($clients)) : ?>
                <div class="ccb-empty">
                    Контрагенты не найдены.
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function client_bonus_page($client)
    {
        ?>
        <div class="ccb-page-heading">
            <small>Программа лояльности</small>
            <h1>Мои бонусы</h1>
            <p>
                Баланс, оплата заказов и запросы на вывод.
            </p>
        </div>

        <div class="ccb-kpi-grid">
            <?php
            $this->kpi(
                'Общий баланс',
                $this->money(
                    $this->total_balance($client->ID)
                ),
                true
            );

            $this->kpi(
                'Доступно',
                $this->money(
                    $this->balance($client->ID)
                ),
                true
            );

            $this->kpi(
                'Зарезервировано',
                $this->money(
                    $this->reserved($client->ID)
                ),
                true
            );

            $this->kpi(
                'Персональная скидка',
                $this->discount($client->ID) . '%'
            );

            $this->kpi(
                'Реферальный процент',
                $this->referral_rate($client->ID) . '%'
            );
            ?>
        </div>

        <div class="ccb-two-columns">
            <div class="ccb-panel">
                <div class="ccb-panel-heading">
                    <h2>Запросить вывод</h2>
                </div>

                <form method="post" class="ccb-withdraw-form">
                    <?php
                    wp_nonce_field(
                        'ccb_create_withdrawal',
                        'ccb_nonce'
                    );
                    ?>

                    <input
                        type="hidden"
                        name="ccb_action"
                        value="create_withdrawal"
                    >

                    <label>
                        <span>Сумма</span>
                        <input
                            type="number"
                            name="amount"
                            min="<?php echo esc_attr(
                                $this->min_withdrawal($client->ID)
                            ); ?>"
                            max="<?php echo esc_attr(
                                $this->balance($client->ID)
                            ); ?>"
                            step="0.01"
                            required
                        >
                    </label>

                    <label>
                        <span>Способ выплаты</span>
                        <select name="payment_method" required>
                            <option value="">Выберите способ</option>
                            <option value="Банковский перевод">
                                Банковский перевод
                            </option>
                            <option value="Перевод на карту">
                                Перевод на карту
                            </option>
                            <option value="Расчётный счёт">
                                Расчётный счёт
                            </option>
                        </select>
                    </label>

                    <label>
                        <span>Реквизиты</span>
                        <textarea
                            name="requisites"
                            rows="4"
                            required
                        ></textarea>
                    </label>

                    <label>
                        <span>Комментарий</span>
                        <textarea
                            name="comment"
                            rows="2"
                        ></textarea>
                    </label>

                    <button type="submit">
                        Отправить запрос менеджеру
                    </button>
                </form>
            </div>

            <div class="ccb-panel">
                <div class="ccb-panel-heading">
                    <h2>Реферальная ссылка</h2>
                </div>

                <div class="ccb-copy-row">
                    <input
                        type="text"
                        readonly
                        value="<?php echo esc_attr(
                            $this->referral_link($client->ID)
                        ); ?>"
                    >

                    <button
                        type="button"
                        data-ccb-copy="<?php echo esc_attr(
                            $this->referral_link($client->ID)
                        ); ?>"
                    >
                        Копировать
                    </button>
                </div>

                <div class="ccb-panel-heading ccb-history-heading">
                    <h2>История операций</h2>
                </div>

                <?php
                $this->render_history(
                    $this->history($client->ID, 100)
                );
                ?>
            </div>
        </div>

        <div class="ccb-panel">
            <div class="ccb-panel-heading">
                <h2>Мои запросы на вывод</h2>
            </div>

            <?php
            $this->render_client_withdrawals(
                $this->user_withdrawals($client->ID)
            );
            ?>
        </div>
        <?php
    }

    private function render_history($entries)
    {
        if (empty($entries)) {
            echo '<div class="ccb-empty">Операций пока нет.</div>';
            return;
        }

        echo '<div class="ccb-history">';

        foreach ($entries as $entry) {
            $amount = (float) $entry->amount;

            echo '<div class="ccb-history-row">';

            echo '<div><strong>' .
                esc_html($this->entry_label(
                    $entry->entry_type
                )) .
                '</strong><small>' .
                esc_html($entry->note) .
                ' · ' .
                esc_html(get_date_from_gmt(
                    $entry->created_at,
                    'd.m.Y H:i'
                )) .
                '</small></div>';

            echo '<b class="' .
                ($amount >= 0 ? 'positive' : 'negative') .
                '">' .
                ($amount >= 0 ? '+' : '−') .
                wp_kses_post($this->money(abs($amount))) .
                '</b>';

            echo '</div>';
        }

        echo '</div>';
    }

    private function render_manager_withdrawals($requests)
    {
        if (empty($requests)) {
            echo '<div class="ccb-empty">Запросов пока нет.</div>';
            return;
        }

        echo '<div class="ccb-withdrawals">';

        foreach ($requests as $request) {
            $client = get_userdata($request->user_id);

            echo '<details class="ccb-withdrawal">';

            echo '<summary>';

            echo '<div><strong>' .
                esc_html(
                    $client instanceof WP_User
                        ? $this->client_title($client)
                        : 'Клиент #' . $request->user_id
                ) .
                '</strong><small>' .
                esc_html(get_date_from_gmt(
                    $request->created_at,
                    'd.m.Y H:i'
                )) .
                '</small></div>';

            echo '<b>' .
                wp_kses_post($this->money($request->amount)) .
                '</b>';

            echo '<span class="status-' .
                esc_attr($request->status) .
                '">' .
                esc_html($this->withdrawal_label(
                    $request->status
                )) .
                '</span>';

            echo '</summary>';

            echo '<div class="ccb-withdrawal-body">';

            echo '<p><strong>Способ:</strong> ' .
                esc_html($request->payment_method) .
                '</p>';

            echo '<p><strong>Реквизиты:</strong><br>' .
                nl2br(esc_html($request->requisites)) .
                '</p>';

            if ($request->client_comment) {
                echo '<p><strong>Комментарий:</strong><br>' .
                    nl2br(esc_html(
                        $request->client_comment
                    )) .
                    '</p>';
            }

            if (
                in_array(
                    $request->status,
                    ['pending', 'approved'],
                    true
                )
            ) {
                echo '<form method="post" class="ccb-status-form">';

                wp_nonce_field(
                    'ccb_change_withdrawal_status',
                    'ccb_nonce'
                );

                echo '<input type="hidden" name="ccb_action"
                    value="change_withdrawal_status">';

                echo '<input type="hidden" name="request_id"
                    value="' . esc_attr($request->id) . '">';

                echo '<textarea name="manager_comment"
                    rows="2"
                    placeholder="Комментарий менеджера"></textarea>';

                echo '<select name="new_status">';

                if ($request->status === 'pending') {
                    echo '<option value="approved">Одобрить</option>';
                }

                if ($request->status === 'approved') {
                    echo '<option value="paid">Выплачено</option>';
                }

                echo '<option value="rejected">Отклонить</option>';
                echo '</select>';

                echo '<button type="submit">Изменить статус</button>';

                echo '</form>';
            }

            echo '</div>';
            echo '</details>';
        }

        echo '</div>';
    }

    private function render_client_withdrawals($requests)
    {
        if (empty($requests)) {
            echo '<div class="ccb-empty">Запросов пока нет.</div>';
            return;
        }

        echo '<div class="ccb-client-withdrawals">';

        foreach ($requests as $request) {
            echo '<div class="ccb-client-withdrawal">';

            echo '<div><strong>#' .
                esc_html($request->id) .
                ' · ' .
                wp_kses_post($this->money($request->amount)) .
                '</strong><small>' .
                esc_html(get_date_from_gmt(
                    $request->created_at,
                    'd.m.Y H:i'
                )) .
                '</small></div>';

            echo '<span class="status-' .
                esc_attr($request->status) .
                '">' .
                esc_html($this->withdrawal_label(
                    $request->status
                )) .
                '</span>';

            if ($request->status === 'pending') {
                echo '<form method="post">';

                wp_nonce_field(
                    'ccb_cancel_withdrawal',
                    'ccb_nonce'
                );

                echo '<input type="hidden" name="ccb_action"
                    value="cancel_withdrawal">';

                echo '<input type="hidden" name="request_id"
                    value="' . esc_attr($request->id) . '">';

                echo '<button type="submit">Отменить</button>';
                echo '</form>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    public function profile_fields($user)
    {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $manager_id  = absint(get_user_meta(
            $user->ID,
            self::MANAGER_META,
            true
        ));

        $referrer_id = absint(get_user_meta(
            $user->ID,
            self::REFERRER_META,
            true
        ));

        wp_nonce_field(
            'ccb_save_profile',
            'ccb_profile_nonce'
        );
        ?>
        <div class="ccb-admin-box">
            <h2>ЦСС B2B — единые настройки</h2>

            <div class="ccb-admin-grid">
                <?php
                $this->admin_input(
                    'ccb_company',
                    'Название компании',
                    $this->company($user->ID)
                );

                $this->admin_input(
                    'ccb_bin',
                    'БИН',
                    $this->meta_value(
                        $user->ID,
                        [self::BIN_META, '_cc_company_bin']
                    )
                );

                $this->admin_input(
                    'ccb_contract',
                    'Договор',
                    $this->meta_value(
                        $user->ID,
                        ['_cc_b2b_contract', '_cc_contract_number']
                    )
                );

                $this->admin_input(
                    'ccb_discount',
                    'Персональная скидка / бонус, %',
                    $this->discount($user->ID),
                    'number',
                    '0.01'
                );

                $this->admin_input(
                    'ccb_referral_rate',
                    'Реферальный процент, %',
                    $this->referral_rate($user->ID),
                    'number',
                    '0.01'
                );

                $this->admin_input(
                    'ccb_credit_limit',
                    'Кредитный лимит',
                    $this->meta_value(
                        $user->ID,
                        ['_cc_b2b_credit_limit', '_cc_credit_limit']
                    ),
                    'number',
                    '0.01'
                );

                $this->admin_input(
                    'ccb_credit_used',
                    'Использовано кредита',
                    $this->meta_value(
                        $user->ID,
                        ['_cc_b2b_credit_used', '_cc_credit_used']
                    ),
                    'number',
                    '0.01'
                );

                $this->admin_input(
                    'ccb_max_payment',
                    'Максимальная оплата бонусами, %',
                    $this->max_payment($user->ID),
                    'number',
                    '0.01'
                );

                $this->admin_input(
                    'ccb_min_payment',
                    'Минимальная оплата бонусами',
                    $this->min_payment($user->ID),
                    'number',
                    '0.01'
                );

                $this->admin_input(
                    'ccb_min_withdrawal',
                    'Минимальный вывод',
                    $this->min_withdrawal($user->ID),
                    'number',
                    '0.01'
                );
                ?>

                <label>
                    <span>Персональный менеджер</span>

                    <select name="ccb_manager_id">
                        <option value="0">Не назначен</option>

                        <?php foreach ($this->managers() as $manager) : ?>
                            <option
                                value="<?php echo esc_attr(
                                    $manager->ID
                                ); ?>"
                                <?php selected(
                                    $manager_id,
                                    $manager->ID
                                ); ?>
                            >
                                <?php echo esc_html(
                                    $this->full_name($manager)
                                ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Кто пригласил</span>

                    <select name="ccb_referrer_id">
                        <option value="0">Никто</option>

                        <?php
                        foreach (
                            get_users(['number' => -1])
                            as $referrer
                        ) :
                            if (
                                !$this->is_client($referrer) ||
                                (int) $referrer->ID ===
                                (int) $user->ID
                            ) {
                                continue;
                            }
                            ?>
                            <option
                                value="<?php echo esc_attr(
                                    $referrer->ID
                                ); ?>"
                                <?php selected(
                                    $referrer_id,
                                    $referrer->ID
                                ); ?>
                            >
                                <?php echo esc_html(
                                    $this->client_title($referrer)
                                ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="ccb-admin-check">
                    <input
                        type="checkbox"
                        name="ccb_allow_payment"
                        value="yes"
                        <?php checked(
                            $this->allow_payment($user->ID)
                        ); ?>
                    >
                    Разрешить оплату бонусами
                </label>

                <div class="ccb-admin-balance">
                    <span>Доступный баланс</span>
                    <strong>
                        <?php echo wp_kses_post(
                            $this->money(
                                $this->balance($user->ID)
                            )
                        ); ?>
                    </strong>
                </div>

                <div class="ccb-admin-balance">
                    <span>Зарезервировано</span>
                    <strong>
                        <?php echo wp_kses_post(
                            $this->money(
                                $this->reserved($user->ID)
                            )
                        ); ?>
                    </strong>
                </div>
            </div>
        </div>
        <?php
    }

    private function admin_input(
        $name,
        $label,
        $value,
        $type = 'text',
        $step = ''
    ) {
        ?>
        <label>
            <span><?php echo esc_html($label); ?></span>

            <input
                type="<?php echo esc_attr($type); ?>"
                name="<?php echo esc_attr($name); ?>"
                value="<?php echo esc_attr($value); ?>"
                <?php echo $step !== ''
                    ? 'step="' . esc_attr($step) . '"'
                    : ''; ?>
                <?php echo $type === 'number'
                    ? 'min="0"'
                    : ''; ?>
            >
        </label>
        <?php
    }

    public function save_profile_fields($user_id)
    {
        if (
            !current_user_can('edit_user', $user_id) ||
            empty($_POST['ccb_profile_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash(
                    $_POST['ccb_profile_nonce']
                )),
                'ccb_save_profile'
            )
        ) {
            return;
        }

        $company = sanitize_text_field(wp_unslash(
            $_POST['ccb_company'] ?? ''
        ));

        $bin = sanitize_text_field(wp_unslash(
            $_POST['ccb_bin'] ?? ''
        ));

        $contract = sanitize_text_field(wp_unslash(
            $_POST['ccb_contract'] ?? ''
        ));

        $discount = $this->rate(
            $this->posted_number('ccb_discount')
        );

        update_user_meta($user_id, self::COMPANY_META, $company);
        update_user_meta($user_id, '_cc_company_name', $company);
        update_user_meta($user_id, 'billing_company', $company);

        update_user_meta($user_id, self::BIN_META, $bin);
        update_user_meta($user_id, '_cc_company_bin', $bin);

        update_user_meta($user_id, '_cc_b2b_contract', $contract);
        update_user_meta($user_id, '_cc_contract_number', $contract);

        update_user_meta($user_id, self::DISCOUNT_META, $discount);
        update_user_meta($user_id, self::OLD_RATE_META, $discount);

        update_user_meta(
            $user_id,
            self::REFERRAL_RATE_META,
            $this->rate(
                $this->posted_number('ccb_referral_rate')
            )
        );

        update_user_meta(
            $user_id,
            '_cc_b2b_credit_limit',
            max(0, $this->posted_number('ccb_credit_limit'))
        );

        update_user_meta(
            $user_id,
            '_cc_b2b_credit_used',
            max(0, $this->posted_number('ccb_credit_used'))
        );

        update_user_meta(
            $user_id,
            self::MAX_PAYMENT_META,
            $this->rate(
                $this->posted_number('ccb_max_payment')
            )
        );

        update_user_meta(
            $user_id,
            self::MIN_PAYMENT_META,
            max(0, $this->posted_number('ccb_min_payment'))
        );

        update_user_meta(
            $user_id,
            self::MIN_WITHDRAW_META,
            max(0, $this->posted_number('ccb_min_withdrawal'))
        );

        update_user_meta(
            $user_id,
            self::ALLOW_PAYMENT_META,
            !empty($_POST['ccb_allow_payment'])
                ? 'yes'
                : 'no'
        );

        $manager_id = absint($_POST['ccb_manager_id'] ?? 0);

        if ($manager_id) {
            update_user_meta(
                $user_id,
                self::MANAGER_META,
                $manager_id
            );
        } else {
            delete_user_meta(
                $user_id,
                self::MANAGER_META
            );
        }

        $referrer_id = absint($_POST['ccb_referrer_id'] ?? 0);

        if ($referrer_id && $referrer_id !== $user_id) {
            update_user_meta(
                $user_id,
                self::REFERRER_META,
                $referrer_id
            );
        } else {
            delete_user_meta(
                $user_id,
                self::REFERRER_META
            );
        }
    }

    public function frontend_assets()
    {
        if (!is_user_logged_in()) {
            return;
        }

        wp_register_style(
            'ccb-bonus-dashboard',
            false,
            [],
            self::VERSION
        );

        wp_enqueue_style('ccb-bonus-dashboard');

        wp_add_inline_style(
            'ccb-bonus-dashboard',
            $this->styles()
        );

        wp_register_script(
            'ccb-bonus-dashboard',
            '',
            ['jquery'],
            self::VERSION,
            true
        );

        wp_enqueue_script('ccb-bonus-dashboard');

        wp_add_inline_script(
            'ccb-bonus-dashboard',
            "
            document.addEventListener('click', function(event) {
                var copyButton = event.target.closest('[data-ccb-copy]');

                if (copyButton) {
                    var text = copyButton.getAttribute('data-ccb-copy');

                    if (text && navigator.clipboard) {
                        navigator.clipboard.writeText(text).then(function() {
                            var oldText = copyButton.textContent;
                            copyButton.textContent = 'Скопировано';

                            setTimeout(function() {
                                copyButton.textContent = oldText;
                            }, 1500);
                        });
                    }
                }

                if (event.target.closest('.ccb-apply-checkout-bonus')) {
                    if (window.jQuery) {
                        jQuery(document.body).trigger('update_checkout');
                    }
                }
            });
            "
        );
    }

    public function admin_assets()
    {
        wp_register_style(
            'ccb-admin',
            false,
            [],
            self::VERSION
        );

        wp_enqueue_style('ccb-admin');

        wp_add_inline_style(
            'ccb-admin',
            '
            .ccb-admin-box{
                margin:25px 0;
                padding:24px;
                background:#fff;
                border:1px solid #dcdcde;
                border-radius:14px
            }
            .ccb-admin-grid{
                display:grid;
                grid-template-columns:repeat(3,minmax(220px,1fr));
                gap:16px;
                margin-top:20px
            }
            .ccb-admin-grid label>span{
                display:block;
                margin-bottom:6px;
                font-weight:600
            }
            .ccb-admin-grid input:not([type=checkbox]),
            .ccb-admin-grid select{
                width:100%;
                max-width:none
            }
            .ccb-admin-check{
                display:flex;
                align-items:center;
                gap:8px
            }
            .ccb-admin-balance{
                padding:15px;
                background:#f5f7fa;
                border-radius:10px
            }
            .ccb-admin-balance span,
            .ccb-admin-balance strong{
                display:block
            }
            .ccb-admin-balance strong{
                margin-top:6px;
                font-size:18px
            }
            @media(max-width:900px){
                .ccb-admin-grid{grid-template-columns:1fr}
            }
            '
        );
    }

    private function styles()
    {
        return '
        .ccb-integrated-cabinet,.ccb-bonus-section{
            --navy:#293750;
            --blue:#5272e8;
            --green:#168d62;
            --red:#d25249;
            --orange:#df923e;
            --muted:#778196;
            --border:#e2e7ef;
            color:#293248;
            width:100%;
            font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif
        }
        .ccb-integrated-cabinet *,.ccb-bonus-section *{
            box-sizing:border-box
        }
        .ccb-main-nav{
            display:flex;
            gap:8px;
            margin-bottom:18px;
            padding:8px;
            overflow-x:auto;
            background:#fff;
            border:1px solid var(--border);
            border-radius:14px;
            box-shadow:0 5px 20px rgba(30,44,67,.05)
        }
        .ccb-main-nav a{
            padding:12px 17px;
            color:#69758a;
            border-radius:9px;
            text-decoration:none;
            white-space:nowrap;
            font-size:12px;
            font-weight:750
        }
        .ccb-main-nav a.is-active{
            color:#fff;
            background:var(--navy)
        }
        .ccb-dashboard{
            display:flex;
            flex-direction:column;
            gap:16px
        }
        .ccb-hero{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:25px;
            padding:28px;
            color:#fff;
            background:linear-gradient(135deg,#192538,#435a80);
            border-radius:18px;
            box-shadow:0 13px 34px rgba(28,42,64,.17)
        }
        .ccb-hero small{
            color:#aabfff;
            font-weight:800;
            text-transform:uppercase
        }
        .ccb-hero h1{
            margin:7px 0;
            color:#fff;
            font-size:30px
        }
        .ccb-hero p{
            margin:0;
            color:rgba(255,255,255,.7)
        }
        .ccb-hero-button,.ccb-actions a{
            padding:12px 17px;
            color:#fff!important;
            background:var(--blue);
            border-radius:9px;
            text-decoration:none;
            font-size:12px;
            font-weight:700
        }
        .ccb-hero-balance{
            min-width:230px;
            padding:18px;
            background:rgba(255,255,255,.1);
            border:1px solid rgba(255,255,255,.2);
            border-radius:13px
        }
        .ccb-hero-balance span,
        .ccb-hero-balance strong{
            display:block
        }
        .ccb-hero-balance strong{
            margin-top:6px;
            color:#fff;
            font-size:28px
        }
        .ccb-kpi-grid{
            display:grid;
            grid-template-columns:repeat(5,minmax(0,1fr));
            gap:12px
        }
        .ccb-kpi{
            min-height:100px;
            padding:18px;
            background:#fff;
            border:1px solid var(--border);
            border-radius:13px;
            box-shadow:0 4px 18px rgba(31,46,69,.04)
        }
        .ccb-kpi span,.ccb-kpi strong{
            display:block
        }
        .ccb-kpi span{
            color:var(--muted);
            font-size:10px
        }
        .ccb-kpi strong{
            margin-top:10px;
            color:var(--navy);
            font-size:18px
        }
        .ccb-two-columns{
            display:grid;
            grid-template-columns:minmax(0,1.4fr) minmax(320px,.6fr);
            gap:16px
        }
        .ccb-panel{
            min-width:0;
            margin-bottom:16px;
            padding:20px;
            background:#fff;
            border:1px solid var(--border);
            border-radius:15px;
            box-shadow:0 5px 21px rgba(31,46,69,.04)
        }
        .ccb-panel-heading{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:16px
        }
        .ccb-panel-heading h2{
            margin:0;
            color:var(--navy);
            font-size:17px
        }
        .ccb-panel-heading a{
            color:var(--blue);
            text-decoration:none;
            font-size:11px;
            font-weight:700
        }
        .ccb-order-row{
            display:grid;
            grid-template-columns:70px minmax(120px,1fr) 80px 105px 110px;
            gap:10px;
            align-items:center;
            min-height:62px;
            border-bottom:1px solid #edf0f5
        }
        .ccb-order-row:last-child{
            border-bottom:0
        }
        .ccb-order-row small,.ccb-order-row strong{
            display:block
        }
        .ccb-order-row small{
            color:var(--muted);
            font-size:9px
        }
        .ccb-order-row strong{
            overflow:hidden;
            color:#374157;
            font-size:11px;
            text-overflow:ellipsis;
            white-space:nowrap
        }
        .ccb-order-row>b{
            text-align:right;
            white-space:nowrap
        }
        .ccb-status{
            padding:6px 8px;
            color:#316b53;
            background:#e9f7f0;
            border-radius:20px;
            font-size:9px;
            font-weight:700;
            text-align:center
        }
        .ccb-page-heading{
            margin-bottom:16px;
            padding:24px;
            color:#fff;
            background:linear-gradient(135deg,#202d43,#465d83);
            border-radius:16px
        }
        .ccb-page-heading small{
            color:#afc0ff;
            text-transform:uppercase;
            font-weight:800
        }
        .ccb-page-heading h1{
            margin:6px 0;
            color:#fff
        }
        .ccb-page-heading p{
            margin:0;
            color:rgba(255,255,255,.7)
        }
        .ccb-search{
            display:flex;
            gap:8px;
            margin-bottom:15px
        }
        .ccb-search input{
            flex:1
        }
        .ccb-search input,.ccb-search button,
        .ccb-settings-form input,.ccb-settings-form select,
        .ccb-settings-form button,.ccb-withdraw-form input,
        .ccb-withdraw-form select,.ccb-withdraw-form textarea,
        .ccb-withdraw-form button,.ccb-copy-row input,
        .ccb-copy-row button,.ccb-status-form textarea,
        .ccb-status-form select,.ccb-status-form button{
            min-height:42px;
            padding:9px 11px;
            border:1px solid var(--border);
            border-radius:8px
        }
        .ccb-search button,.ccb-settings-form button,
        .ccb-withdraw-form button,.ccb-copy-row button,
        .ccb-status-form button{
            color:#fff;
            background:var(--blue);
            border-color:var(--blue);
            cursor:pointer;
            font-weight:700
        }
        .ccb-client-settings{
            margin-bottom:10px;
            border:1px solid var(--border);
            border-radius:12px
        }
        .ccb-client-settings>summary{
            display:grid;
            grid-template-columns:minmax(180px,1fr) repeat(3,150px);
            gap:12px;
            align-items:center;
            padding:16px;
            cursor:pointer
        }
        .ccb-client-settings summary strong,
        .ccb-client-settings summary small{
            display:block
        }
        .ccb-client-settings summary small{
            margin-top:4px;
            color:var(--muted)
        }
        .ccb-client-settings-body{
            padding:18px;
            border-top:1px solid var(--border)
        }
        .ccb-settings-form{
            display:grid;
            grid-template-columns:repeat(3,1fr);
            gap:13px
        }
        .ccb-settings-form label>span,
        .ccb-withdraw-form label>span{
            display:block;
            margin-bottom:5px;
            color:#566174;
            font-size:11px;
            font-weight:700
        }
        .ccb-settings-form input,
        .ccb-settings-form select,
        .ccb-withdraw-form input,
        .ccb-withdraw-form select,
        .ccb-withdraw-form textarea{
            width:100%
        }
        .ccb-checkbox{
            display:flex!important;
            align-items:center;
            gap:7px
        }
        .ccb-checkbox input{
            width:auto!important;
            min-height:0
        }
        .ccb-withdraw-form{
            display:flex;
            flex-direction:column;
            gap:12px
        }
        .ccb-copy-row{
            display:flex;
            gap:8px
        }
        .ccb-copy-row input{
            flex:1;
            min-width:0
        }
        .ccb-history-heading{
            margin-top:25px
        }
        .ccb-history-row{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:11px 0;
            border-bottom:1px solid #edf0f5
        }
        .ccb-history-row strong,.ccb-history-row small{
            display:block
        }
        .ccb-history-row small{
            margin-top:4px;
            color:var(--muted);
            font-size:9px
        }
        .positive{color:var(--green)!important}
        .negative{color:var(--red)!important}
        .ccb-withdrawal{
            margin-bottom:8px;
            border:1px solid var(--border);
            border-radius:10px
        }
        .ccb-withdrawal>summary{
            display:grid;
            grid-template-columns:minmax(150px,1fr) 120px 120px;
            gap:10px;
            align-items:center;
            padding:13px;
            cursor:pointer
        }
        .ccb-withdrawal summary strong,
        .ccb-withdrawal summary small{
            display:block
        }
        .ccb-withdrawal summary small{
            margin-top:3px;
            color:var(--muted)
        }
        .ccb-withdrawal-body{
            padding:15px;
            border-top:1px solid var(--border)
        }
        .ccb-status-form{
            display:grid;
            grid-template-columns:1fr 180px auto;
            gap:8px
        }
        .status-pending{color:#a16b1e}
        .status-approved{color:#315fc6}
        .status-paid{color:var(--green)}
        .status-rejected,.status-cancelled{color:var(--red)}
        .ccb-client-withdrawal{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:12px;
            border-bottom:1px solid var(--border)
        }
        .ccb-client-withdrawal strong,
        .ccb-client-withdrawal small{
            display:block
        }
        .ccb-client-withdrawal small{
            color:var(--muted)
        }
        .ccb-message{
            margin-bottom:14px;
            padding:12px;
            border-radius:8px
        }
        .ccb-message.success{
            color:#176742;
            background:#e9f7f0
        }
        .ccb-message.error{
            color:#a33a34;
            background:#ffe9e7
        }
        .ccb-empty{
            padding:25px;
            color:var(--muted);
            background:#f7f9fc;
            border-radius:10px;
            text-align:center
        }
        .ccb-actions{
            display:flex;
            flex-wrap:wrap;
            gap:8px
        }
        .ccb-checkout-bonus{
            margin:15px 0;
            padding:16px;
            border:1px solid var(--border);
            border-radius:10px
        }
        .ccb-checkout-bonus label span{
            display:block;
            margin-bottom:5px
        }
        .ccb-checkout-bonus input{
            width:100%;
            margin-bottom:10px
        }
        @media(max-width:1100px){
            .ccb-kpi-grid{grid-template-columns:repeat(3,1fr)}
            .ccb-two-columns{grid-template-columns:1fr}
            .ccb-settings-form{grid-template-columns:repeat(2,1fr)}
        }
        @media(max-width:760px){
            .ccb-hero{align-items:flex-start;flex-direction:column}
            .ccb-hero-balance{width:100%;min-width:0}
            .ccb-kpi-grid{grid-template-columns:repeat(2,1fr)}
            .ccb-settings-form{grid-template-columns:1fr}
            .ccb-client-settings>summary{grid-template-columns:1fr}
            .ccb-order-row{grid-template-columns:1fr 1fr}
            .ccb-order-row>b{text-align:left}
            .ccb-withdrawal>summary{grid-template-columns:1fr}
            .ccb-status-form{grid-template-columns:1fr}
        }
        @media(max-width:480px){
            .ccb-kpi-grid{grid-template-columns:1fr}
            .ccb-copy-row{flex-direction:column}
        }
        ';
    }
}

register_activation_hook(
    __FILE__,
    ['CC_B2B_Bonuses', 'activate']
);

CC_B2B_Bonuses::instance();
