<?php
/**
 * Plugin Name: ЦСС B2B Кабинет
 * Description: Кабинет клиентов и менеджеров, контрагенты, поиск, карточка клиента и заказы WooCommerce.
 * Version: 0.2.0
 * Author: ЦСС Кабель
 * Text Domain: cc-b2b
 */

defined('ABSPATH') || exit;

final class CC_B2B_Cabinet
{
    const VERSION       = '0.2.0';
    const CLIENT_ROLE   = 'cc_b2b_client';
    const MANAGER_ROLE  = 'cc_b2b_manager';
    const MANAGER_META  = '_cc_b2b_manager_id';
    const COMPANY_META  = '_cc_b2b_company';
    const BIN_META      = '_cc_b2b_bin';
    const ORDER_MANAGER = '_cc_b2b_manager_id';

    public function __construct()
    {
        add_action('init', [$this, 'register_shortcode']);

        add_action('show_user_profile', [$this, 'user_fields']);
        add_action('edit_user_profile', [$this, 'user_fields']);

        add_action('personal_options_update', [$this, 'save_user_fields']);
        add_action('edit_user_profile_update', [$this, 'save_user_fields']);

        add_filter('manage_users_columns', [$this, 'users_columns']);
        add_filter(
            'manage_users_custom_column',
            [$this, 'users_column_content'],
            10,
            3
        );

        add_action(
            'woocommerce_checkout_create_order',
            [$this, 'attach_manager_to_order'],
            10,
            2
        );

        add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);
        add_action('admin_init', [$this, 'restrict_admin']);
        add_action(
            'template_redirect',
            [$this, 'handle_manager_order_status']
        );
    }

    public static function activate()
    {
        add_role(
            self::CLIENT_ROLE,
            'Клиент',
            [
                'read'               => true,
                'cc_view_cabinet'    => true,
                'cc_create_orders'   => true,
                'cc_view_own_orders' => true,
            ]
        );

        add_role(
            self::MANAGER_ROLE,
            'Менеджер',
            [
                'read'                  => true,
                'cc_view_cabinet'       => true,
                'cc_view_own_orders'    => true,
                'cc_view_client_orders' => true,
                'cc_create_orders'      => true,
                'cc_manage_clients'     => true,
            ]
        );

        $administrator = get_role('administrator');

        if ($administrator) {
            $capabilities = [
                'cc_view_cabinet',
                'cc_create_orders',
                'cc_view_own_orders',
                'cc_view_client_orders',
                'cc_manage_clients',
                'cc_manage_company',
                'cc_manage_settings',
            ];

            foreach ($capabilities as $capability) {
                $administrator->add_cap($capability);
            }
        }

        flush_rewrite_rules();
    }

    public function register_shortcode()
    {
        add_shortcode(
            'cc_b2b_cabinet',
            [$this, 'cabinet_shortcode']
        );
    }

    private function user_has_role_type(
        $user,
        array $aliases,
        array $names
    ) {
        $user = $user ?: wp_get_current_user();

        if (!$user || !$user->exists()) {
            return false;
        }

        $registered_roles = wp_roles()->roles;

        foreach ((array) $user->roles as $role_slug) {
            if (in_array($role_slug, $aliases, true)) {
                return true;
            }

            $role_name = isset($registered_roles[$role_slug]['name'])
                ? $registered_roles[$role_slug]['name']
                : '';

            $role_text = $role_slug . ' ' . $role_name;

            foreach ($names as $name) {
                if (false !== stripos($role_text, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function is_client($user = null)
    {
        $user = $user ?: wp_get_current_user();

        if (!$user || !$user->exists()) {
            return false;
        }

        if (
            $user->has_cap('cc_view_own_orders') &&
            !$user->has_cap('cc_manage_clients')
        ) {
            return true;
        }

        return $this->user_has_role_type(
            $user,
            [
                self::CLIENT_ROLE,
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
                'клиент',
                'Закупщик',
                'закупщик',
                'Client',
                'Customer',
                'Buyer',
                'Purchaser',
            ]
        );
    }

    private function is_manager($user = null)
    {
        $user = $user ?: wp_get_current_user();

        if (!$user || !$user->exists()) {
            return false;
        }

        if (
            $user->has_cap('cc_manage_clients') &&
            !$user->has_cap('manage_options')
        ) {
            return true;
        }

        return $this->user_has_role_type(
            $user,
            [
                self::MANAGER_ROLE,
                'b2b_manager',
                'manager',
                'lcc_b2b_manager',
            ],
            [
                'Менеджер',
                'менеджер',
                'Manager',
            ]
        );
    }

    private function get_all_managers()
    {
        $users = get_users([
            'number'  => -1,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ]);

        $managers = [];

        foreach ($users as $user) {
            if ($this->is_manager($user)) {
                $managers[] = $user;
            }
        }

        return $managers;
    }

    public function user_fields($user)
    {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $fields = [
            'cc_b2b_company' => [
                'label' => 'Компания',
                'meta'  => self::COMPANY_META,
                'type'  => 'text',
            ],
            'cc_b2b_bin' => [
                'label' => 'БИН',
                'meta'  => self::BIN_META,
                'type'  => 'text',
            ],
            'cc_b2b_contract' => [
                'label' => 'Договор',
                'meta'  => '_cc_b2b_contract',
                'type'  => 'text',
            ],
            'cc_b2b_payment_delay' => [
                'label' => 'Отсрочка',
                'meta'  => '_cc_b2b_payment_delay',
                'type'  => 'text',
            ],
            'cc_b2b_price_model' => [
                'label' => 'Модель цен',
                'meta'  => '_cc_b2b_price_model',
                'type'  => 'text',
            ],
            'cc_b2b_priority' => [
                'label' => 'Приоритет',
                'meta'  => '_cc_b2b_priority',
                'type'  => 'text',
            ],
            'cc_b2b_credit_limit' => [
                'label' => 'Лимит кредита',
                'meta'  => '_cc_b2b_credit_limit',
                'type'  => 'number',
            ],
            'cc_b2b_credit_used' => [
                'label' => 'Использовано кредита',
                'meta'  => '_cc_b2b_credit_used',
                'type'  => 'number',
            ],
            'cc_b2b_overdue_days' => [
                'label' => 'Дней просрочки',
                'meta'  => '_cc_b2b_overdue_days',
                'type'  => 'number',
            ],
        ];

        $manager_id = absint(
            get_user_meta(
                $user->ID,
                self::MANAGER_META,
                true
            )
        );

        wp_nonce_field(
            'cc_b2b_save_user',
            'cc_b2b_nonce'
        );
        ?>
        <h2>ЦСС B2B</h2>

        <table class="form-table">
            <?php foreach ($fields as $field_name => $field) : ?>
                <?php
                $value = get_user_meta(
                    $user->ID,
                    $field['meta'],
                    true
                );
                ?>
                <tr>
                    <th>
                        <label for="<?php echo esc_attr($field_name); ?>">
                            <?php echo esc_html($field['label']); ?>
                        </label>
                    </th>

                    <td>
                        <input
                            type="<?php echo esc_attr($field['type']); ?>"
                            class="regular-text"
                            id="<?php echo esc_attr($field_name); ?>"
                            name="<?php echo esc_attr($field_name); ?>"
                            value="<?php echo esc_attr($value); ?>"
                            <?php echo 'number' === $field['type'] ? 'min="0" step="0.01"' : ''; ?>
                        >
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (current_user_can('manage_options')) : ?>
                <tr>
                    <th>
                        <label for="cc_b2b_manager_id">
                            Менеджер клиента
                        </label>
                    </th>

                    <td>
                        <select
                            id="cc_b2b_manager_id"
                            name="cc_b2b_manager_id"
                        >
                            <option value="0">Не назначен</option>

                            <?php foreach ($this->get_all_managers() as $manager) : ?>
                                <option
                                    value="<?php echo esc_attr($manager->ID); ?>"
                                    <?php selected($manager_id, $manager->ID); ?>
                                >
                                    <?php echo esc_html($manager->display_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <p class="description">
                            Менеджер будет видеть клиента и его заказы.
                        </p>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    public function save_user_fields($user_id)
    {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        if (
            empty($_POST['cc_b2b_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(
                    wp_unslash($_POST['cc_b2b_nonce'])
                ),
                'cc_b2b_save_user'
            )
        ) {
            return;
        }

        $text_fields = [
            'cc_b2b_company'       => self::COMPANY_META,
            'cc_b2b_bin'           => self::BIN_META,
            'cc_b2b_contract'      => '_cc_b2b_contract',
            'cc_b2b_payment_delay' => '_cc_b2b_payment_delay',
            'cc_b2b_price_model'   => '_cc_b2b_price_model',
            'cc_b2b_priority'      => '_cc_b2b_priority',
        ];

        foreach ($text_fields as $post_key => $meta_key) {
            if (isset($_POST[$post_key])) {
                update_user_meta(
                    $user_id,
                    $meta_key,
                    sanitize_text_field(
                        wp_unslash($_POST[$post_key])
                    )
                );
            }
        }

        $number_fields = [
            'cc_b2b_credit_limit' => '_cc_b2b_credit_limit',
            'cc_b2b_credit_used'  => '_cc_b2b_credit_used',
            'cc_b2b_overdue_days' => '_cc_b2b_overdue_days',
        ];

        foreach ($number_fields as $post_key => $meta_key) {
            if (isset($_POST[$post_key])) {
                update_user_meta(
                    $user_id,
                    $meta_key,
                    floatval(
                        wp_unslash($_POST[$post_key])
                    )
                );
            }
        }

        if (current_user_can('manage_options')) {
            $manager_id = isset($_POST['cc_b2b_manager_id'])
                ? absint($_POST['cc_b2b_manager_id'])
                : 0;

            update_user_meta(
                $user_id,
                self::MANAGER_META,
                $manager_id
            );
        }
    }

    public function users_columns($columns)
    {
        $columns['cc_company'] = 'Компания';
        $columns['cc_manager'] = 'Менеджер';

        return $columns;
    }

    public function users_column_content(
        $output,
        $column,
        $user_id
    ) {
        if ('cc_company' === $column) {
            return esc_html(
                $this->get_client_company($user_id)
            );
        }

        if ('cc_manager' === $column) {
            $manager_id = absint(
                get_user_meta(
                    $user_id,
                    self::MANAGER_META,
                    true
                )
            );

            if (!$manager_id) {
                return '—';
            }

            $manager = get_userdata($manager_id);

            return $manager
                ? esc_html($manager->display_name)
                : '—';
        }

        return $output;
    }

    public function attach_manager_to_order($order, $data)
    {
        $customer_id = $order->get_customer_id();

        if (!$customer_id) {
            return;
        }

        $manager_id = absint(
            get_user_meta(
                $customer_id,
                self::MANAGER_META,
                true
            )
        );

        if ($manager_id) {
            $order->update_meta_data(
                self::ORDER_MANAGER,
                $manager_id
            );
        }
    }

    private function get_client_company($user_id)
    {
        if ($user_id instanceof WP_User) {
            $user_id = $user_id->ID;
        }

        $user_id = absint($user_id);

        $meta_keys = [
            self::COMPANY_META,
            '_css_b2b_company_name',
            'css_b2b_company_name',
            '_b2b_company_name',
            'b2b_company_name',
            'billing_company',
        ];

        foreach ($meta_keys as $meta_key) {
            $company = trim(
                (string) get_user_meta(
                    $user_id,
                    $meta_key,
                    true
                )
            );

            if ('' !== $company) {
                return $company;
            }
        }

        return '';
    }

    private function get_client_full_name($user)
    {
        if (!($user instanceof WP_User)) {
            return '';
        }

        $first_name = trim(
            (string) get_user_meta(
                $user->ID,
                'first_name',
                true
            )
        );

        $last_name = trim(
            (string) get_user_meta(
                $user->ID,
                'last_name',
                true
            )
        );

        $full_name = trim($first_name . ' ' . $last_name);

        if ($full_name) {
            return $full_name;
        }

        return $user->display_name ?: $user->user_login;
    }

    private function client_text_contains($text, $search)
    {
        $text   = (string) $text;
        $search = trim((string) $search);

        if ('' === $search) {
            return true;
        }

        if (function_exists('mb_stripos')) {
            return false !== mb_stripos(
                $text,
                $search,
                0,
                'UTF-8'
            );
        }

        return false !== stripos($text, $search);
    }

    private function get_initials($text, $length = 2)
    {
        $text = trim((string) $text);

        if (!$text) {
            return 'К';
        }

        $words = preg_split('/\s+/u', $text);
        $result = '';

        foreach ($words as $word) {
            if (!$word) {
                continue;
            }

            $result .= function_exists('mb_substr')
                ? mb_substr($word, 0, 1, 'UTF-8')
                : substr($word, 0, 1);

            if (
                function_exists('mb_strlen')
                    ? mb_strlen($result, 'UTF-8') >= $length
                    : strlen($result) >= $length
            ) {
                break;
            }
        }

        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($result, 'UTF-8');
        }

        return strtoupper($result);
    }

    private function get_manager_clients(
        $manager_id,
        $search = ''
    ) {
        $manager_id = absint($manager_id);
        $search     = trim((string) $search);

        if (!$manager_id) {
            return [];
        }

        $all_users = get_users([
            'number'  => -1,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ]);

        $clients = [];

        foreach ($all_users as $candidate) {
            if (!$this->is_client($candidate)) {
                continue;
            }

            $assigned_manager_id = absint(
                get_user_meta(
                    $candidate->ID,
                    self::MANAGER_META,
                    true
                )
            );

            if ($assigned_manager_id !== $manager_id) {
                continue;
            }

            $company   = $this->get_client_company($candidate->ID);
            $full_name = $this->get_client_full_name($candidate);

            $searchable = implode(
                ' ',
                [
                    $company,
                    $full_name,
                    $candidate->display_name,
                    $candidate->user_email,
                ]
            );

            if (!$this->client_text_contains($searchable, $search)) {
                continue;
            }

            $clients[] = $candidate;
        }

        usort(
            $clients,
            function ($first, $second) {
                $first_title = $this->get_client_company($first->ID)
                    ?: $this->get_client_full_name($first);

                $second_title = $this->get_client_company($second->ID)
                    ?: $this->get_client_full_name($second);

                return strnatcasecmp(
                    $first_title,
                    $second_title
                );
            }
        );

        return $clients;
    }

    private function get_orders_for_clients(array $clients, $limit = 50)
    {
        if (!function_exists('wc_get_orders')) {
            return [];
        }

        $orders_by_id = [];

        foreach ($clients as $client) {
            if (!($client instanceof WP_User)) {
                continue;
            }

            $customer_orders = wc_get_orders([
                'customer_id' => $client->ID,
                'limit'       => $limit,
                'orderby'     => 'date',
                'order'       => 'DESC',
                'status'      => array_keys(
                    wc_get_order_statuses()
                ),
            ]);

            foreach ($customer_orders as $order) {
                $orders_by_id[$order->get_id()] = $order;
            }

            if ($client->user_email) {
                $email_orders = wc_get_orders([
                    'billing_email' => $client->user_email,
                    'limit'         => $limit,
                    'orderby'       => 'date',
                    'order'         => 'DESC',
                    'status'        => array_keys(
                        wc_get_order_statuses()
                    ),
                ]);

                foreach ($email_orders as $order) {
                    $orders_by_id[$order->get_id()] = $order;
                }
            }
        }

        $orders = array_values($orders_by_id);

        usort(
            $orders,
            function ($first, $second) {
                $first_date  = $first->get_date_created();
                $second_date = $second->get_date_created();

                $first_time = $first_date
                    ? $first_date->getTimestamp()
                    : 0;

                $second_time = $second_date
                    ? $second_date->getTimestamp()
                    : 0;

                return $second_time <=> $first_time;
            }
        );

        return array_slice($orders, 0, $limit);
    }

    private function get_manager_orders(
        $manager_id,
        $search = '',
        $selected_client_id = 0
    ) {
        $manager_id        = absint($manager_id);
        $selected_client_id = absint($selected_client_id);

        if (!$manager_id) {
            return [];
        }

        if ($selected_client_id) {
            $selected_client = get_userdata(
                $selected_client_id
            );

            if (
                !$selected_client ||
                !$this->is_client($selected_client)
            ) {
                return [];
            }

            $assigned_manager_id = absint(
                get_user_meta(
                    $selected_client->ID,
                    self::MANAGER_META,
                    true
                )
            );

            if ($assigned_manager_id !== $manager_id) {
                return [];
            }

            return $this->get_orders_for_clients(
                [$selected_client],
                50
            );
        }

        $clients = $this->get_manager_clients(
            $manager_id,
            $search
        );

        return $this->get_orders_for_clients(
            $clients,
            50
        );
    }

    private function get_client_orders($client_id)
    {
        $client = get_userdata(absint($client_id));

        if (!$client) {
            return [];
        }

        return $this->get_orders_for_clients(
            [$client],
            30
        );
    }

    private function manager_can_view_client(
        $manager_id,
        $client_id
    ) {
        $manager_id = absint($manager_id);
        $client_id  = absint($client_id);

        if (current_user_can('manage_options')) {
            return true;
        }

        if (!$manager_id || !$client_id) {
            return false;
        }

        $client = get_userdata($client_id);

        if (!$client || !$this->is_client($client)) {
            return false;
        }

        return absint(
            get_user_meta(
                $client_id,
                self::MANAGER_META,
                true
            )
        ) === $manager_id;
    }

    private function client_meta_value(
        $client_id,
        array $keys
    ) {
        foreach ($keys as $key) {
            $value = get_user_meta(
                $client_id,
                $key,
                true
            );

            if ('' !== trim((string) $value)) {
                return $value;
            }
        }

        return '';
    }

    private function get_current_url()
    {
        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? wp_unslash($_SERVER['REQUEST_URI'])
            : '/';

        return home_url($request_uri);
    }

    public function cabinet_shortcode()
    {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="cc-b2b-message">Войдите, чтобы открыть кабинет. <a href="%s">Войти</a></div>',
                esc_url(wp_login_url(get_permalink()))
            );
        }

        if (!class_exists('WooCommerce')) {
            return '<div class="cc-b2b-message">Для работы кабинета требуется WooCommerce.</div>';
        }

        $user = wp_get_current_user();

        $view = isset($_GET['cc_view'])
            ? sanitize_key(wp_unslash($_GET['cc_view']))
            : '';

        $is_detail_page = (
            'client_details' === $view &&
            (
                $this->is_manager($user) ||
                current_user_can('manage_options')
            )
        );

        ob_start();

        $this->cabinet_styles();
        ?>
        <div class="cc-b2b-cabinet <?php echo $is_detail_page ? 'is-client-detail' : ''; ?>">

            <?php if (!$is_detail_page) : ?>
                <div class="cc-b2b-header">
                    <div>
                        <div class="cc-b2b-label">
                            Личный кабинет
                        </div>

                        <h2>
                            <?php echo esc_html($user->display_name); ?>
                        </h2>
                    </div>

                    <a
                        class="cc-b2b-button secondary"
                        href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>"
                    >
                        Выйти
                    </a>
                </div>
            <?php endif; ?>

            <?php
            if (current_user_can('manage_options')) {
                if ('client_details' === $view) {
                    $client_id = isset($_GET['cc_client_id'])
                        ? absint($_GET['cc_client_id'])
                        : 0;

                    $this->render_client_details(
                        $user->ID,
                        $client_id
                    );
                } else {
                    $this->administrator_dashboard();
                }
            } elseif ($this->is_manager($user)) {
                $this->manager_dashboard($user);
            } elseif ($this->is_client($user)) {
                $this->client_dashboard($user);
            } else {
                echo '<p>Для пользователя не назначена роль кабинета.</p>';
            }
            ?>
        </div>
        <?php

        return ob_get_clean();
    }

    private function client_dashboard($user)
    {
        $company = $this->get_client_company($user->ID);
        $bin     = get_user_meta(
            $user->ID,
            self::BIN_META,
            true
        );

        $manager_id = absint(
            get_user_meta(
                $user->ID,
                self::MANAGER_META,
                true
            )
        );

        $manager = $manager_id
            ? get_userdata($manager_id)
            : false;

        $orders = $this->get_client_orders($user->ID);
        ?>
        <div class="cc-b2b-grid">
            <section class="cc-b2b-card">
                <div class="cc-b2b-label">Компания</div>

                <h3>
                    <?php echo esc_html($company ?: 'Компания не указана'); ?>
                </h3>

                <?php if ($bin) : ?>
                    <p>БИН: <?php echo esc_html($bin); ?></p>
                <?php endif; ?>

                <p>
                    Менеджер:
                    <strong>
                        <?php
                        echo esc_html(
                            $manager
                                ? $manager->display_name
                                : 'не назначен'
                        );
                        ?>
                    </strong>
                </p>
            </section>

            <section class="cc-b2b-card">
                <div class="cc-b2b-label">Заказы</div>

                <h3>
                    <?php echo esc_html(count($orders)); ?>
                    последних заказов
                </h3>

                <?php if (function_exists('wc_get_cart_url')) : ?>
                    <a
                        class="cc-b2b-button"
                        href="<?php echo esc_url(wc_get_cart_url()); ?>"
                    >
                        Открыть корзину
                    </a>
                <?php endif; ?>
            </section>
        </div>

        <?php $this->orders_table($orders, false); ?>
        <?php
    }

    private function manager_dashboard($user)
    {
        $view = isset($_GET['cc_view'])
            ? sanitize_key(wp_unslash($_GET['cc_view']))
            : '';

        $selected_client_id = isset($_GET['cc_client_id'])
            ? absint($_GET['cc_client_id'])
            : 0;

        if (
            'client_details' === $view &&
            $selected_client_id
        ) {
            $this->render_client_details(
                $user->ID,
                $selected_client_id
            );

            return;
        }

        $search = isset($_GET['cc_client_search'])
            ? sanitize_text_field(
                wp_unslash($_GET['cc_client_search'])
            )
            : '';

        $clients = $this->get_manager_clients(
            $user->ID,
            $search
        );

        $orders = $this->get_manager_orders(
            $user->ID,
            $search,
            $selected_client_id
        );

        $selected_client = false;

        if ($selected_client_id) {
            $candidate = get_userdata($selected_client_id);

            if (
                $candidate &&
                $this->manager_can_view_client(
                    $user->ID,
                    $candidate->ID
                )
            ) {
                $selected_client = $candidate;
            }
        }
        ?>

        <div class="cc-b2b-grid">
            <section class="cc-b2b-card cc-b2b-clients-card">
                <div class="cc-b2b-label">Контрагенты</div>

                <h3>
                    <?php echo esc_html(count($clients)); ?>
                    <?php echo 1 === count($clients) ? 'клиент' : 'клиентов'; ?>
                </h3>

                <form
                    class="cc-b2b-client-search"
                    method="get"
                    action="<?php echo esc_url(get_permalink()); ?>"
                >
                    <input
                        type="search"
                        name="cc_client_search"
                        value="<?php echo esc_attr($search); ?>"
                        placeholder="Компания, имя или фамилия"
                    >

                    <button
                        class="cc-b2b-button"
                        type="submit"
                    >
                        Найти
                    </button>

                    <?php if ($search) : ?>
                        <a
                            class="cc-b2b-reset-search"
                            href="<?php echo esc_url(get_permalink()); ?>"
                        >
                            Сбросить
                        </a>
                    <?php endif; ?>
                </form>

                <?php if ($clients) : ?>
                    <div class="cc-b2b-clients-list">
                        <?php foreach ($clients as $client) : ?>
                            <?php
                            $company = $this->get_client_company(
                                $client->ID
                            );

                            $full_name = $this->get_client_full_name(
                                $client
                            );

                            $orders_url = add_query_arg(
                                [
                                    'cc_client_search' => $search,
                                    'cc_client_id'     => $client->ID,
                                ],
                                get_permalink()
                            );

                            $details_url = add_query_arg(
                                [
                                    'cc_view'      => 'client_details',
                                    'cc_client_id' => $client->ID,
                                ],
                                get_permalink()
                            );

                            $is_selected = (
                                $selected_client_id === (int) $client->ID
                            );
                            ?>

                            <div class="cc-b2b-client-item <?php echo $is_selected ? 'is-selected' : ''; ?>">
                                <div class="cc-b2b-client-avatar">
                                    <?php
                                    echo esc_html(
                                        $this->get_initials(
                                            $company ?: $full_name
                                        )
                                    );
                                    ?>
                                </div>

                                <div class="cc-b2b-client-information">
                                    <strong class="cc-b2b-client-company">
                                        <?php echo esc_html($company ?: $full_name); ?>
                                    </strong>

                                    <span class="cc-b2b-client-name">
                                        <?php
                                        echo esc_html(
                                            $company
                                                ? $full_name
                                                : $client->user_email
                                        );
                                        ?>
                                    </span>
                                </div>

                                <div class="cc-b2b-client-actions">
                                    <a
                                        href="<?php echo esc_url($orders_url); ?>"
                                        class="cc-client-orders-btn"
                                    >
                                        Заказы
                                    </a>

                                    <a
                                        href="<?php echo esc_url($details_url); ?>"
                                        class="cc-client-details-btn"
                                    >
                                        Подробнее
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($search) : ?>
                    <p>
                        По запросу
                        «<?php echo esc_html($search); ?>»
                        ничего не найдено.
                    </p>
                <?php else : ?>
                    <p>Клиенты пока не назначены.</p>
                <?php endif; ?>
            </section>

            <section class="cc-b2b-card">
                <div class="cc-b2b-label">
                    <?php
                    echo $selected_client
                        ? 'Заказы выбранного клиента'
                        : 'Заказы клиентов';
                    ?>
                </div>

                <h3>
                    <?php echo esc_html(count($orders)); ?>
                    заказов
                </h3>

                <?php if ($selected_client) : ?>
                    <p>
                        <?php
                        $selected_company = $this->get_client_company(
                            $selected_client->ID
                        );

                        echo esc_html(
                            $selected_company
                                ?: $this->get_client_full_name(
                                    $selected_client
                                )
                        );
                        ?>
                    </p>

                    <a
                        class="cc-b2b-reset-search"
                        href="<?php
                        echo esc_url(
                            add_query_arg(
                                'cc_client_search',
                                $search,
                                get_permalink()
                            )
                        );
                        ?>"
                    >
                        Показать заказы всех найденных
                    </a>
                <?php endif; ?>
            </section>
        </div>

        <?php $this->orders_table($orders, true); ?>
        <?php
    }

    private function render_client_details(
        $manager_id,
        $client_id
    ) {
        if (
            !$client_id ||
            !$this->manager_can_view_client(
                $manager_id,
                $client_id
            )
        ) {
            echo '<div class="cc-b2b-message">Нет доступа к этому контрагенту.</div>';
            return;
        }

        $client = get_userdata($client_id);

        if (!$client) {
            echo '<div class="cc-b2b-message">Контрагент не найден.</div>';
            return;
        }

        $company = $this->get_client_company($client->ID);
        $name    = $this->get_client_full_name($client);

        $bin = $this->client_meta_value(
            $client_id,
            [
                self::BIN_META,
                'cc_b2b_bin',
                'billing_company_id',
            ]
        );

        $phone = $this->client_meta_value(
            $client_id,
            [
                'billing_phone',
                'phone',
            ]
        );

        $contract = $this->client_meta_value(
            $client_id,
            [
                '_cc_b2b_contract',
                'cc_b2b_contract',
            ]
        );

        $payment_delay = $this->client_meta_value(
            $client_id,
            [
                '_cc_b2b_payment_delay',
                'cc_b2b_payment_delay',
            ]
        );

        $price_model = $this->client_meta_value(
            $client_id,
            [
                '_cc_b2b_price_model',
                'cc_b2b_price_model',
            ]
        );

        $priority = $this->client_meta_value(
            $client_id,
            [
                '_cc_b2b_priority',
                'cc_b2b_priority',
            ]
        );

        $credit_limit = (float) $this->client_meta_value(
            $client_id,
            [
                '_cc_b2b_credit_limit',
                'cc_b2b_credit_limit',
            ]
        );

        $credit_used = (float) $this->client_meta_value(
            $client_id,
            [
                '_cc_b2b_credit_used',
                'cc_b2b_credit_used',
            ]
        );

        $overdue_days = absint(
            $this->client_meta_value(
                $client_id,
                [
                    '_cc_b2b_overdue_days',
                    'cc_b2b_overdue_days',
                ]
            )
        );

        $credit_percent = $credit_limit > 0
            ? min(
                100,
                ($credit_used / $credit_limit) * 100
            )
            : 0;

        $orders = $this->get_orders_for_clients(
            [$client],
            50
        );

        $turnover     = 0;
        $year_orders  = 0;
        $current_year = (int) wp_date('Y');

        foreach ($orders as $order) {
            $created = $order->get_date_created();

            if (
                $created &&
                (int) $created->date_i18n('Y') === $current_year
            ) {
                $turnover += (float) $order->get_total();
                $year_orders++;
            }
        }

        $average_order = $year_orders > 0
            ? $turnover / $year_orders
            : 0;

        $registered = wp_date(
            'd.m.Y',
            strtotime($client->user_registered)
        );

        $tab = isset($_GET['cc_tab'])
            ? sanitize_key(wp_unslash($_GET['cc_tab']))
            : 'activity';

        $allowed_tabs = [
            'activity',
            'orders',
            'prices',
            'documents',
            'notes',
        ];

        if (!in_array($tab, $allowed_tabs, true)) {
            $tab = 'activity';
        }

        $base_url = add_query_arg(
            [
                'cc_view'      => 'client_details',
                'cc_client_id' => $client_id,
            ],
            get_permalink()
        );

        $back_url = remove_query_arg(
            [
                'cc_view',
                'cc_client_id',
                'cc_tab',
                'cc_status_updated',
            ],
            get_permalink()
        );

        $shop_url = function_exists('wc_get_page_permalink')
            ? wc_get_page_permalink('shop')
            : home_url('/shop/');

        $phone_href = preg_replace(
            '/[^0-9+]/',
            '',
            (string) $phone
        );
        ?>

        <div class="cc-profile-page">
            <div class="cc-profile-toolbar">
                <div class="cc-profile-heading">
                    <div class="cc-profile-breadcrumbs">
                        <a href="<?php echo esc_url($back_url); ?>">
                            Контрагенты
                        </a>

                        <span>›</span>

                        <span>
                            <?php echo esc_html($company ?: $name); ?>
                        </span>
                    </div>

                    <h1>
                        <?php echo esc_html($company ?: $name); ?>
                    </h1>
                </div>

                <div class="cc-profile-actions">
                    <a
                        class="cc-profile-action"
                        href="mailto:<?php echo esc_attr($client->user_email); ?>"
                    >
                        ✉ Написать
                    </a>

                    <?php if ($phone) : ?>
                        <a
                            class="cc-profile-action"
                            href="tel:<?php echo esc_attr($phone_href); ?>"
                        >
                            ☎ Позвонить
                        </a>
                    <?php endif; ?>

                    <a
                        class="cc-profile-action primary"
                        href="<?php echo esc_url($shop_url); ?>"
                    >
                        Оформить заказ от лица
                    </a>
                </div>
            </div>

            <div class="cc-profile-layout">
                <aside class="cc-profile-sidebar">
                    <section class="cc-profile-card cc-profile-company-card">
                        <div class="cc-profile-company-head">
                            <div class="cc-profile-company-logo">
                                <?php
                                echo esc_html(
                                    $this->get_initials(
                                        $company ?: $name
                                    )
                                );
                                ?>
                            </div>

                            <div class="cc-profile-company-title">
                                <h2>
                                    <?php echo esc_html($company ?: $name); ?>
                                </h2>

                                <?php if ($bin) : ?>
                                    <span>
                                        БИН <?php echo esc_html($bin); ?>
                                    </span>
                                <?php endif; ?>

                                <div class="cc-profile-active">
                                    ● Активный
                                </div>
                            </div>
                        </div>

                        <dl class="cc-profile-company-data">
                            <div>
                                <dt>Клиент с</dt>
                                <dd><?php echo esc_html($registered); ?></dd>
                            </div>

                            <div>
                                <dt>Договор</dt>
                                <dd>
                                    <?php echo esc_html($contract ?: 'Не указан'); ?>
                                </dd>
                            </div>

                            <div>
                                <dt>Отсрочка</dt>
                                <dd>
                                    <?php echo esc_html($payment_delay ?: 'Не указана'); ?>
                                </dd>
                            </div>

                            <div>
                                <dt>Модель цен</dt>
                                <dd>
                                    <?php echo esc_html($price_model ?: 'Не указана'); ?>
                                </dd>
                            </div>

                            <div>
                                <dt>Приоритет</dt>
                                <dd>
                                    <span class="cc-profile-priority">
                                        <?php echo esc_html($priority ?: 'Не указан'); ?>
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section class="cc-profile-card cc-profile-credit-card">
                        <div class="cc-profile-section-label">
                            Лимит кредита
                        </div>

                        <strong class="cc-profile-credit-value">
                            <?php echo wp_kses_post(wc_price($credit_used)); ?>
                            /
                            <?php echo wp_kses_post(wc_price($credit_limit)); ?>
                        </strong>

                        <div class="cc-profile-credit-track">
                            <span
                                style="width:<?php echo esc_attr($credit_percent); ?>%"
                            ></span>
                        </div>

                        <small>
                            Использовано
                            <?php
                            echo esc_html(
                                number_format_i18n(
                                    $credit_percent,
                                    1
                                )
                            );
                            ?>%
                            ·
                            <?php
                            echo $overdue_days
                                ? esc_html($overdue_days . ' дн. просрочки')
                                : 'без просрочек';
                            ?>
                        </small>
                    </section>

                    <section class="cc-profile-card cc-profile-contacts-card">
                        <div class="cc-profile-section-label">
                            Контакты · ЛПР
                        </div>

                        <div class="cc-profile-contact">
                            <div class="cc-profile-contact-avatar">
                                <?php
                                echo esc_html(
                                    $this->get_initials($name)
                                );
                                ?>
                            </div>

                            <div class="cc-profile-contact-information">
                                <strong>
                                    <?php echo esc_html($name); ?>
                                </strong>

                                <span>Закупщик · ЛПР</span>
                            </div>

                            <?php if ($phone) : ?>
                                <a
                                    class="cc-profile-phone"
                                    href="tel:<?php echo esc_attr($phone_href); ?>"
                                >
                                    ☎
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="cc-profile-contact-links">
                            <a href="mailto:<?php echo esc_attr($client->user_email); ?>">
                                <?php echo esc_html($client->user_email); ?>
                            </a>

                            <?php if ($phone) : ?>
                                <a href="tel:<?php echo esc_attr($phone_href); ?>">
                                    <?php echo esc_html($phone); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </section>
                </aside>

                <main class="cc-profile-main">
                    <div class="cc-profile-statistics">
                        <article class="cc-profile-stat">
                            <span>Оборот · YTD</span>

                            <strong>
                                <?php echo wp_kses_post(wc_price($turnover)); ?>
                            </strong>

                            <small>За текущий год</small>
                        </article>

                        <article class="cc-profile-stat">
                            <span>Заказов</span>

                            <strong>
                                <?php echo esc_html(count($orders)); ?>
                            </strong>

                            <small>Всего найдено</small>
                        </article>

                        <article class="cc-profile-stat">
                            <span>Средний чек</span>

                            <strong>
                                <?php echo wp_kses_post(wc_price($average_order)); ?>
                            </strong>

                            <small>За текущий год</small>
                        </article>

                        <article class="cc-profile-stat">
                            <span>Просрочка</span>

                            <strong>
                                <?php echo esc_html($overdue_days); ?>
                                дней
                            </strong>

                            <small>За всё время</small>
                        </article>
                    </div>

                    <section class="cc-profile-workspace">
                        <nav class="cc-profile-tabs">
                            <?php
                            $tabs = [
                                'activity'  => 'Активность',
                                'orders'    => 'Заказы ' . count($orders),
                                'prices'    => 'Прайс правила',
                                'documents' => 'Документы',
                                'notes'     => 'Заметки',
                            ];

                            foreach ($tabs as $tab_key => $tab_label) :
                                $tab_url = add_query_arg(
                                    'cc_tab',
                                    $tab_key,
                                    $base_url
                                );
                                ?>
                                <a
                                    class="<?php echo $tab === $tab_key ? 'is-active' : ''; ?>"
                                    href="<?php echo esc_url($tab_url); ?>"
                                >
                                    <?php echo esc_html($tab_label); ?>
                                </a>
                            <?php endforeach; ?>
                        </nav>

                        <div class="cc-profile-tab-content">
                            <?php if ('activity' === $tab) : ?>
                                <div class="cc-profile-timeline-title">
                                    Хронология · последние события
                                </div>

                                <?php if ($orders) : ?>
                                    <div class="cc-profile-timeline">
                                        <?php foreach (array_slice($orders, 0, 8) as $order) : ?>
                                            <?php
                                            $order_date = $order->get_date_created();

                                            $orders_tab_url = add_query_arg(
                                                'cc_tab',
                                                'orders',
                                                $base_url
                                            );
                                            ?>

                                            <a
                                                class="cc-profile-event"
                                                href="<?php echo esc_url($orders_tab_url . '#cc-order-' . $order->get_id()); ?>"
                                            >
                                                <div class="cc-profile-event-icon">
                                                    ◈
                                                </div>

                                                <div>
                                                    <strong>
                                                        <?php
                                                        echo esc_html(
                                                            wc_get_order_status_name(
                                                                $order->get_status()
                                                            )
                                                        );
                                                        ?>
                                                        заказ
                                                        №<?php echo esc_html($order->get_order_number()); ?>
                                                    </strong>

                                                    <span>
                                                        Сумма:
                                                        <?php
                                                        echo wp_kses_post(
                                                            $order->get_formatted_order_total()
                                                        );
                                                        ?>
                                                        ·
                                                        <?php
                                                        echo esc_html(
                                                            $order_date
                                                                ? $order_date->date_i18n('d.m.Y · H:i')
                                                                : ''
                                                        );
                                                        ?>
                                                    </span>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else : ?>
                                    <div class="cc-profile-empty">
                                        У контрагента пока нет заказов.
                                    </div>
                                <?php endif; ?>

                            <?php elseif ('orders' === $tab) : ?>

                                <?php $this->orders_table($orders, true); ?>

                            <?php elseif ('prices' === $tab) : ?>

                                <div class="cc-profile-empty">
                                    Модель цен:
                                    <strong>
                                        <?php echo esc_html($price_model ?: 'не указана'); ?>
                                    </strong>
                                </div>

                            <?php elseif ('documents' === $tab) : ?>

                                <div class="cc-profile-empty">
                                    Документы контрагента пока не загружены.
                                </div>

                            <?php elseif ('notes' === $tab) : ?>

                                <div class="cc-profile-empty">
                                    Заметки будут добавлены на следующем этапе.
                                </div>

                            <?php endif; ?>
                        </div>
                    </section>
                </main>
            </div>
        </div>
        <?php
    }

    private function administrator_dashboard()
    {
        $counts = count_users();
        ?>
        <section class="cc-b2b-card">
            <div class="cc-b2b-label">Администратор</div>

            <h3>Управление системой</h3>

            <p>
                Всего пользователей:
                <?php echo esc_html($counts['total_users']); ?>
            </p>

            <a
                class="cc-b2b-button"
                href="<?php echo esc_url(admin_url('users.php')); ?>"
            >
                Управление пользователями
            </a>
        </section>
        <?php
    }

    private function manager_can_access_order(
        $manager_id,
        $order
    ) {
        if (!($order instanceof WC_Order)) {
            return false;
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        $clients = $this->get_manager_clients(
            absint($manager_id),
            ''
        );

        $order_customer_id = absint(
            $order->get_customer_id()
        );

        $order_email = strtolower(
            trim((string) $order->get_billing_email())
        );

        foreach ($clients as $client) {
            if (
                $order_customer_id &&
                $order_customer_id === absint($client->ID)
            ) {
                return true;
            }

            $client_email = strtolower(
                trim((string) $client->user_email)
            );

            if (
                $order_email &&
                $client_email &&
                $order_email === $client_email
            ) {
                return true;
            }
        }

        return false;
    }

    public function handle_manager_order_status()
    {
        $request_method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(
                sanitize_text_field(
                    wp_unslash($_SERVER['REQUEST_METHOD'])
                )
            )
            : '';

        if ('POST' !== $request_method) {
            return;
        }

        $action = isset($_POST['cc_b2b_action'])
            ? sanitize_key(
                wp_unslash($_POST['cc_b2b_action'])
            )
            : '';

        if ('change_order_status' !== $action) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_die('Необходимо войти в систему.');
        }

        $user = wp_get_current_user();

        if (
            !$this->is_manager($user) &&
            !current_user_can('manage_options')
        ) {
            wp_die('Недостаточно прав.');
        }

        $order_id = isset($_POST['cc_order_id'])
            ? absint($_POST['cc_order_id'])
            : 0;

        $nonce = isset($_POST['cc_order_status_nonce'])
            ? sanitize_text_field(
                wp_unslash($_POST['cc_order_status_nonce'])
            )
            : '';

        if (
            !$order_id ||
            !wp_verify_nonce(
                $nonce,
                'cc_change_order_status_' . $order_id
            )
        ) {
            wp_die('Ошибка безопасности.');
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            wp_die('Заказ не найден.');
        }

        if (
            !current_user_can('manage_options') &&
            !$this->manager_can_access_order(
                $user->ID,
                $order
            )
        ) {
            wp_die('Нет доступа к заказу.');
        }

        $new_status = isset($_POST['cc_new_status'])
            ? sanitize_key(
                wp_unslash($_POST['cc_new_status'])
            )
            : '';

        $available_statuses = [];

        foreach (
            wc_get_order_statuses()
            as $status_key => $status_label
        ) {
            $available_statuses[
                str_replace('wc-', '', $status_key)
            ] = $status_label;
        }

        if (!isset($available_statuses[$new_status])) {
            wp_die('Неправильный статус.');
        }

        if ($order->get_status() !== $new_status) {
            $order->update_status(
                $new_status,
                sprintf(
                    'Статус изменён менеджером %s через B2B-кабинет.',
                    $user->display_name
                ),
                true
            );
        }

        $return_url = isset($_POST['cc_return_url'])
            ? esc_url_raw(
                wp_unslash($_POST['cc_return_url'])
            )
            : get_permalink();

        $return_url = wp_validate_redirect(
            $return_url,
            get_permalink()
        );

        $return_url = remove_query_arg(
            'cc_status_updated',
            $return_url
        );

        $return_url = add_query_arg(
            'cc_status_updated',
            $order_id,
            $return_url
        );

        $return_url .= '#cc-order-' . $order_id;

        wp_safe_redirect($return_url);
        exit;
    }

    private function orders_table(
        $orders,
        $show_customer = false
    ) {
        $current_user = wp_get_current_user();

        $can_change_status = (
            $show_customer &&
            (
                $this->is_manager($current_user) ||
                current_user_can('manage_options')
            )
        );
        ?>
        <section class="cc-b2b-card cc-b2b-orders">
            <h3>Заказы</h3>

            <?php if (!empty($_GET['cc_status_updated'])) : ?>
                <div class="cc-b2b-success-message">
                    Статус заказа
                    №<?php echo esc_html(absint($_GET['cc_status_updated'])); ?>
                    успешно изменён.
                </div>
            <?php endif; ?>

            <?php if (!$orders) : ?>
                <p>Заказов пока нет.</p>
            </section>
                <?php
                return;
            endif;
            ?>

            <div class="cc-b2b-orders-accordion">
                <?php foreach ($orders as $order) : ?>
                    <?php
                    $order_id = $order->get_id();

                    $customer_name = trim(
                        $order->get_formatted_billing_full_name()
                    );

                    if (!$customer_name) {
                        $customer = get_userdata(
                            $order->get_customer_id()
                        );

                        $customer_name = $customer
                            ? $customer->display_name
                            : 'Клиент';
                    }

                    $order_date = $order->get_date_created();
                    ?>

                    <details
                        id="cc-order-<?php echo esc_attr($order_id); ?>"
                        class="cc-b2b-order-item"
                        <?php
                        echo (
                            !empty($_GET['cc_status_updated']) &&
                            absint($_GET['cc_status_updated']) === $order_id
                        ) ? 'open' : '';
                        ?>
                    >
                        <summary class="cc-b2b-order-summary">
                            <span>
                                <small>Заказ</small>
                                <strong>
                                    #<?php echo esc_html($order->get_order_number()); ?>
                                </strong>
                            </span>

                            <?php if ($show_customer) : ?>
                                <span class="cc-b2b-order-customer">
                                    <small>Клиент</small>
                                    <strong>
                                        <?php echo esc_html($customer_name); ?>
                                    </strong>
                                </span>
                            <?php endif; ?>

                            <span>
                                <small>Дата</small>
                                <strong>
                                    <?php
                                    echo esc_html(
                                        $order_date
                                            ? $order_date->date_i18n('d.m.Y')
                                            : '—'
                                    );
                                    ?>
                                </strong>
                            </span>

                            <span>
                                <small>Статус</small>
                                <strong>
                                    <?php
                                    echo esc_html(
                                        wc_get_order_status_name(
                                            $order->get_status()
                                        )
                                    );
                                    ?>
                                </strong>
                            </span>

                            <span>
                                <small>Сумма</small>
                                <strong>
                                    <?php
                                    echo wp_kses_post(
                                        $order->get_formatted_order_total()
                                    );
                                    ?>
                                </strong>
                            </span>

                            <span class="cc-b2b-order-arrow"></span>
                        </summary>

                        <div class="cc-b2b-order-content">
                            <h4>Состав заказа</h4>

                            <div class="cc-b2b-products-table-wrap">
                                <table class="cc-b2b-products-table">
                                    <thead>
                                        <tr>
                                            <th>Товар</th>
                                            <th>Артикул</th>
                                            <th>Цена</th>
                                            <th>Количество</th>
                                            <th>Сумма</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php foreach ($order->get_items() as $item) : ?>
                                            <?php
                                            $product = $item->get_product();

                                            $sku = $product
                                                ? $product->get_sku()
                                                : '';

                                            $quantity = $item->get_quantity();

                                            $unit_price = $quantity > 0
                                                ? $item->get_subtotal() / $quantity
                                                : 0;
                                            ?>

                                            <tr>
                                                <td>
                                                    <strong>
                                                        <?php echo esc_html($item->get_name()); ?>
                                                    </strong>

                                                    <?php
                                                    echo wp_kses_post(
                                                        wc_display_item_meta(
                                                            $item,
                                                            [
                                                                'echo'      => false,
                                                                'separator' => ', ',
                                                            ]
                                                        )
                                                    );
                                                    ?>
                                                </td>

                                                <td>
                                                    <?php echo esc_html($sku ?: '—'); ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    echo wp_kses_post(
                                                        wc_price(
                                                            $unit_price,
                                                            [
                                                                'currency' => $order->get_currency(),
                                                            ]
                                                        )
                                                    );
                                                    ?>
                                                </td>

                                                <td>
                                                    <?php echo esc_html($quantity); ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    echo wp_kses_post(
                                                        $order->get_formatted_line_subtotal(
                                                            $item
                                                        )
                                                    );
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="cc-b2b-order-footer">
                                <div class="cc-b2b-order-totals">
                                    <?php foreach ($order->get_order_item_totals() as $total) : ?>
                                        <div class="cc-b2b-order-total-row">
                                            <span>
                                                <?php echo wp_kses_post($total['label']); ?>
                                            </span>

                                            <strong>
                                                <?php echo wp_kses_post($total['value']); ?>
                                            </strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($can_change_status) : ?>
                                    <form
                                        class="cc-b2b-status-form"
                                        method="post"
                                    >
                                        <input
                                            type="hidden"
                                            name="cc_return_url"
                                            value="<?php echo esc_url($this->get_current_url()); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="cc_b2b_action"
                                            value="change_order_status"
                                        >

                                        <input
                                            type="hidden"
                                            name="cc_order_id"
                                            value="<?php echo esc_attr($order_id); ?>"
                                        >

                                        <?php
                                        wp_nonce_field(
                                            'cc_change_order_status_' . $order_id,
                                            'cc_order_status_nonce'
                                        );
                                        ?>

                                        <label for="cc-status-<?php echo esc_attr($order_id); ?>">
                                            Статус заказа
                                        </label>

                                        <select
                                            id="cc-status-<?php echo esc_attr($order_id); ?>"
                                            name="cc_new_status"
                                        >
                                            <?php foreach (wc_get_order_statuses() as $status_key => $status_label) : ?>
                                                <?php
                                                $clean_status = str_replace(
                                                    'wc-',
                                                    '',
                                                    $status_key
                                                );
                                                ?>

                                                <option
                                                    value="<?php echo esc_attr($clean_status); ?>"
                                                    <?php selected($order->get_status(), $clean_status); ?>
                                                >
                                                    <?php echo esc_html($status_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button
                                            type="submit"
                                            class="cc-b2b-button"
                                        >
                                            Поменять статус
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    public function login_redirect(
        $redirect_to,
        $requested_redirect_to,
        $user
    ) {
        if (!($user instanceof WP_User)) {
            return $redirect_to;
        }

        if (
            $this->is_client($user) ||
            $this->is_manager($user)
        ) {
            $cabinet_page = get_page_by_path('cabinet');

            if ($cabinet_page) {
                return get_permalink($cabinet_page);
            }

            if (function_exists('wc_get_page_permalink')) {
                return wc_get_page_permalink('myaccount');
            }
        }

        return $redirect_to;
    }

    public function restrict_admin()
    {
        if (
            !is_user_logged_in() ||
            wp_doing_ajax() ||
            current_user_can('manage_options')
        ) {
            return;
        }

        $user = wp_get_current_user();

        if (
            $this->is_client($user) ||
            $this->is_manager($user)
        ) {
            $cabinet_page = get_page_by_path('cabinet');

            wp_safe_redirect(
                $cabinet_page
                    ? get_permalink($cabinet_page)
                    : home_url('/')
            );

            exit;
        }
    }

    private function cabinet_styles()
    {
        ?>
        <style>
        .cc-b2b-cabinet {
            max-width: 1180px;
            margin: 28px auto;
            padding: 0 18px;
            color: #263247;
            font-family: Inter, Arial, sans-serif;
            box-sizing: border-box;
        }

        .cc-b2b-cabinet * {
            box-sizing: border-box;
        }

        .cc-b2b-cabinet.is-client-detail {
            max-width: 1280px;
        }

        .cc-b2b-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .cc-b2b-header h2,
        .cc-b2b-card h3 {
            margin: 4px 0 12px;
        }

        .cc-b2b-label {
            color: #8590a3;
            font-size: 12px;
            text-transform: uppercase;
        }

        .cc-b2b-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(260px, .55fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        .cc-b2b-card,
        .cc-profile-card,
        .cc-profile-stat,
        .cc-profile-workspace {
            background: #fff;
            border: 1px solid #e4e9f0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(31, 45, 70, .04);
        }

        .cc-b2b-card {
            padding: 20px;
        }

        .cc-b2b-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 9px 17px;
            color: #fff !important;
            background: #314e73;
            border: 0;
            border-radius: 7px;
            text-decoration: none;
            cursor: pointer;
        }

        .cc-b2b-button.secondary {
            color: #314e73 !important;
            background: #fff;
            border: 1px solid #d7dee8;
        }

        .cc-b2b-message {
            padding: 20px;
            background: #fff;
            border: 1px solid #e1e6ed;
            border-radius: 10px;
        }

        .cc-b2b-client-search {
            display: flex;
            gap: 8px;
            align-items: center;
            margin: 16px 0;
        }

        .cc-b2b-client-search input {
            width: 100%;
            min-height: 42px;
            padding: 9px 12px;
            border: 1px solid #ccd5df;
            border-radius: 7px;
            outline: none;
        }

        .cc-b2b-client-search input:focus {
            border-color: #6f87a5;
            box-shadow: 0 0 0 3px rgba(49, 78, 115, .08);
        }

        .cc-b2b-reset-search {
            color: #536b88;
            text-decoration: none;
        }

        .cc-b2b-clients-list {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        .cc-b2b-client-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border: 1px solid #e5eaf0;
            border-radius: 9px;
            background: #fff;
        }

        .cc-b2b-client-item.is-selected {
            border-color: #314e73;
            background: #f3f7fb;
            box-shadow: 0 0 0 1px #314e73;
        }

        .cc-b2b-client-avatar {
            display: flex;
            flex: 0 0 42px;
            width: 42px;
            height: 42px;
            align-items: center;
            justify-content: center;
            color: #365574;
            background: #edf3f9;
            border-radius: 50%;
            font-size: 13px;
            font-weight: 700;
        }

        .cc-b2b-client-information {
            display: flex;
            min-width: 0;
            flex: 1;
            flex-direction: column;
        }

        .cc-b2b-client-company {
            color: #263247;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cc-b2b-client-name {
            margin-top: 3px;
            color: #7d899b;
            font-size: 13px;
        }

        .cc-b2b-client-actions {
            display: flex;
            gap: 8px;
            margin-left: auto;
        }

        .cc-client-orders-btn,
        .cc-client-details-btn {
            padding: 8px 13px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .cc-client-orders-btn {
            color: #314e73;
            background: #edf3f8;
        }

        .cc-client-details-btn {
            color: #fff;
            background: #314e73;
        }

        .cc-b2b-orders-accordion {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .cc-b2b-order-item {
            overflow: hidden;
            background: #fff;
            border: 1px solid #e3e8ef;
            border-radius: 9px;
        }

        .cc-b2b-order-summary {
            display: grid;
            grid-template-columns: 90px 1.3fr 100px 140px 140px 18px;
            gap: 14px;
            align-items: center;
            padding: 15px;
            cursor: pointer;
            list-style: none;
        }

        .cc-b2b-order-summary::-webkit-details-marker {
            display: none;
        }

        .cc-b2b-order-summary small {
            display: block;
            margin-bottom: 4px;
            color: #8691a2;
            font-size: 10px;
            text-transform: uppercase;
        }

        .cc-b2b-order-summary strong {
            color: #263247;
            font-size: 13px;
        }

        .cc-b2b-order-arrow {
            width: 9px;
            height: 9px;
            border-right: 2px solid #738095;
            border-bottom: 2px solid #738095;
            transform: rotate(45deg);
            transition: transform .2s ease;
        }

        .cc-b2b-order-item[open] .cc-b2b-order-arrow {
            transform: rotate(225deg);
        }

        .cc-b2b-order-item[open] .cc-b2b-order-summary {
            background: #f7f9fc;
            border-bottom: 1px solid #e3e8ef;
        }

        .cc-b2b-order-content {
            padding: 20px;
        }

        .cc-b2b-order-content h4 {
            margin: 0 0 15px;
        }

        .cc-b2b-products-table-wrap {
            overflow-x: auto;
        }

        .cc-b2b-products-table {
            width: 100%;
            border-collapse: collapse;
        }

        .cc-b2b-products-table th,
        .cc-b2b-products-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #edf0f4;
            text-align: left;
            vertical-align: top;
        }

        .cc-b2b-products-table th {
            color: #718096;
            font-size: 11px;
            text-transform: uppercase;
        }

        .cc-b2b-order-footer {
            display: flex;
            gap: 30px;
            align-items: flex-end;
            justify-content: space-between;
            margin-top: 20px;
        }

        .cc-b2b-order-totals {
            width: 100%;
            max-width: 360px;
        }

        .cc-b2b-order-total-row {
            display: flex;
            gap: 20px;
            justify-content: space-between;
            padding: 5px 0;
        }

        .cc-b2b-status-form {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: flex-end;
        }

        .cc-b2b-status-form label {
            flex: 0 0 100%;
            color: #718096;
            font-size: 12px;
        }

        .cc-b2b-status-form select {
            min-width: 175px;
            min-height: 42px;
            padding: 8px 10px;
            background: #fff;
            border: 1px solid #ccd5df;
            border-radius: 7px;
        }

        .cc-b2b-success-message {
            margin-bottom: 15px;
            padding: 12px 15px;
            color: #18794e;
            background: #e7f7ef;
            border: 1px solid #b8e5ce;
            border-radius: 7px;
        }

        .cc-profile-toolbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 20px;
        }

        .cc-profile-breadcrumbs {
            display: flex;
            gap: 8px;
            align-items: center;
            color: #8894a5;
            font-size: 12px;
        }

        .cc-profile-breadcrumbs a {
            color: #647892;
            text-decoration: none;
        }

        .cc-profile-heading h1 {
            margin: 5px 0 0;
            color: #263247;
            font-size: 24px;
            line-height: 1.25;
        }

        .cc-profile-actions {
            display: flex;
            gap: 8px;
        }

        .cc-profile-action {
            display: inline-flex;
            min-height: 38px;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            color: #425773;
            background: #fff;
            border: 1px solid #dce2e9;
            border-radius: 7px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .cc-profile-action.primary {
            color: #fff;
            background: #365577;
            border-color: #365577;
        }

        .cc-profile-layout {
            display: grid;
            grid-template-columns: 310px minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }

        .cc-profile-sidebar {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .cc-profile-card {
            padding: 18px;
        }

        .cc-profile-company-head {
            display: flex;
            gap: 13px;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .cc-profile-company-logo {
            display: flex;
            flex: 0 0 54px;
            width: 54px;
            height: 54px;
            align-items: center;
            justify-content: center;
            color: #3a5877;
            background: #edf3f9;
            border-radius: 10px;
            font-weight: 700;
        }

        .cc-profile-company-title {
            min-width: 0;
        }

        .cc-profile-company-title h2 {
            margin: 0 0 3px;
            color: #263247;
            font-size: 16px;
        }

        .cc-profile-company-title > span {
            color: #8591a1;
            font-size: 11px;
        }

        .cc-profile-active {
            display: inline-flex;
            margin-top: 8px;
            padding: 4px 8px;
            color: #24925a;
            background: #e6f7ed;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

        .cc-profile-company-data {
            margin: 0;
        }

        .cc-profile-company-data > div {
            display: grid;
            grid-template-columns: 90px 1fr;
            gap: 12px;
            padding: 5px 0;
        }

        .cc-profile-company-data dt {
            color: #8b96a6;
            font-size: 12px;
        }

        .cc-profile-company-data dd {
            margin: 0;
            color: #4a5668;
            font-size: 12px;
            text-align: right;
        }

        .cc-profile-priority {
            display: inline-block;
            padding: 3px 7px;
            color: #c45b5b;
            background: #fff0f0;
            border-radius: 12px;
        }

        .cc-profile-section-label {
            margin-bottom: 14px;
            color: #8b96a6;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .cc-profile-credit-value {
            display: block;
            color: #2d394d;
            font-size: 18px;
        }

        .cc-profile-credit-track {
            height: 7px;
            margin: 13px 0 7px;
            overflow: hidden;
            background: #edf0f4;
            border-radius: 20px;
        }

        .cc-profile-credit-track span {
            display: block;
            height: 100%;
            background: #385979;
            border-radius: 20px;
        }

        .cc-profile-credit-card small {
            color: #8a95a5;
            font-size: 10px;
        }

        .cc-profile-contact {
            display: flex;
            gap: 11px;
            align-items: center;
        }

        .cc-profile-contact-avatar {
            display: flex;
            flex: 0 0 34px;
            width: 34px;
            height: 34px;
            align-items: center;
            justify-content: center;
            color: #d46a6a;
            background: #fff1f1;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 700;
        }

        .cc-profile-contact-information {
            display: flex;
            min-width: 0;
            flex: 1;
            flex-direction: column;
        }

        .cc-profile-contact-information strong {
            color: #39465a;
            font-size: 12px;
        }

        .cc-profile-contact-information span {
            margin-top: 2px;
            color: #8b96a6;
            font-size: 10px;
        }

        .cc-profile-phone {
            color: #8491a3;
            text-decoration: none;
        }

        .cc-profile-contact-links {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin: 12px 0 0 45px;
        }

        .cc-profile-contact-links a {
            color: #607793;
            font-size: 11px;
            text-decoration: none;
            word-break: break-all;
        }

        .cc-profile-main {
            min-width: 0;
        }

        .cc-profile-statistics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .cc-profile-stat {
            min-height: 120px;
            padding: 17px;
        }

        .cc-profile-stat > span {
            display: block;
            min-height: 30px;
            color: #8d98a8;
            font-size: 11px;
        }

        .cc-profile-stat strong {
            display: block;
            margin: 6px 0 8px;
            color: #253248;
            font-size: 20px;
            line-height: 1.2;
        }

        .cc-profile-stat small {
            color: #789985;
            font-size: 10px;
        }

        .cc-profile-workspace {
            overflow: hidden;
        }

        .cc-profile-tabs {
            display: flex;
            min-height: 48px;
            align-items: stretch;
            gap: 0;
            padding: 0 14px;
            border-bottom: 1px solid #e8ecf1;
            overflow-x: auto;
        }

        .cc-profile-tabs a {
            display: inline-flex;
            align-items: center;
            padding: 0 14px;
            color: #778397;
            border-bottom: 2px solid transparent;
            text-decoration: none;
            font-size: 12px;
            white-space: nowrap;
        }

        .cc-profile-tabs a.is-active {
            color: #355473;
            border-bottom-color: #355473;
            font-weight: 600;
        }

        .cc-profile-tab-content {
            min-height: 280px;
            padding: 20px;
        }

        .cc-profile-timeline-title {
            margin-bottom: 16px;
            color: #8a95a5;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .cc-profile-timeline {
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .cc-profile-timeline::before {
            position: absolute;
            top: 16px;
            bottom: 16px;
            left: 14px;
            width: 1px;
            background: #e3e8ee;
            content: "";
        }

        .cc-profile-event {
            position: relative;
            display: flex;
            gap: 12px;
            padding: 9px 0;
            color: inherit;
            text-decoration: none;
        }

        .cc-profile-event-icon {
            position: relative;
            z-index: 1;
            display: flex;
            flex: 0 0 29px;
            width: 29px;
            height: 29px;
            align-items: center;
            justify-content: center;
            color: #52708e;
            background: #eef3f8;
            border-radius: 50%;
            font-size: 11px;
        }

        .cc-profile-event strong {
            display: block;
            color: #455166;
            font-size: 12px;
        }

        .cc-profile-event span {
            display: block;
            margin-top: 3px;
            color: #8b96a5;
            font-size: 10px;
        }

        .cc-profile-empty {
            padding: 24px;
            color: #768397;
            background: #f8fafc;
            border: 1px dashed #dce3ea;
            border-radius: 9px;
        }

        .cc-profile-tab-content > .cc-b2b-card {
            padding: 0;
            border: 0;
            box-shadow: none;
        }

        @media (max-width: 1000px) {
            .cc-profile-layout {
                grid-template-columns: 270px minmax(0, 1fr);
            }

            .cc-profile-statistics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .cc-profile-actions {
                flex-wrap: wrap;
                justify-content: flex-end;
            }
        }

        @media (max-width: 800px) {
            .cc-b2b-grid,
            .cc-profile-layout {
                grid-template-columns: 1fr;
            }

            .cc-profile-toolbar {
                flex-direction: column;
            }

            .cc-profile-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .cc-profile-action {
                flex: 1;
            }

            .cc-b2b-order-summary {
                grid-template-columns: 1fr 1fr 18px;
            }

            .cc-b2b-order-customer {
                grid-column: span 2;
            }

            .cc-b2b-order-footer {
                align-items: stretch;
                flex-direction: column;
            }

            .cc-b2b-status-form {
                align-items: stretch;
                flex-direction: column;
            }

            .cc-b2b-status-form select,
            .cc-b2b-status-form button {
                width: 100%;
            }
        }

        @media (max-width: 600px) {
            .cc-b2b-cabinet {
                margin: 15px auto;
                padding: 0 10px;
            }

            .cc-b2b-client-search,
            .cc-b2b-client-actions,
            .cc-profile-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .cc-b2b-client-item {
                flex-wrap: wrap;
            }

            .cc-b2b-client-actions {
                width: 100%;
                margin: 5px 0 0;
            }

            .cc-client-orders-btn,
            .cc-client-details-btn {
                text-align: center;
            }

            .cc-profile-statistics {
                grid-template-columns: 1fr 1fr;
            }

            .cc-profile-stat {
                min-height: 105px;
                padding: 13px;
            }

            .cc-profile-stat strong {
                font-size: 16px;
            }

            .cc-profile-tabs {
                padding: 0 5px;
            }

            .cc-profile-tab-content {
                padding: 14px;
            }
        }
        </style>
        <?php
    }
}

register_activation_hook(
    __FILE__,
    ['CC_B2B_Cabinet', 'activate']
);

new CC_B2B_Cabinet();
