<?php
/**
 * Vamariz Roles and Invitation Token Manager
 *
 * @package VamarizInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_Roles {

    const ROLE_SLUG = 'installment_customer';
    const ROLE_NAME = 'مشتری اقساطی';
    const OPTION_INVITES = 'vamariz_invitations';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('init', array($this, 'register_role'));
        add_action('show_user_profile', array($this, 'render_user_profile_fields'));
        add_action('edit_user_profile', array($this, 'render_user_profile_fields'));
        add_action('personal_options_update', array($this, 'save_user_profile_fields'));
        add_action('edit_user_profile_update', array($this, 'save_user_profile_fields'));

        // Registration and checkout with invite token
        add_action('woocommerce_register_form', array($this, 'render_invite_registration_fields'));
        add_action('woocommerce_after_checkout_registration_form', array($this, 'render_invite_registration_fields'));
        add_action('woocommerce_before_checkout_form', array($this, 'render_invite_registration_fields'));
        add_action('woocommerce_created_customer', array($this, 'handle_customer_registration_with_invite'), 10, 3);
        add_action('user_register', array($this, 'handle_customer_registration_with_invite'), 10, 1);
        add_action('wp_login', array($this, 'handle_user_login_with_invite'), 10, 2);
        add_action('template_redirect', array($this, 'capture_invite_cookie'));
        add_action('init', array($this, 'capture_invite_cookie'), 20);
    }

    /**
     * Static activation callback.
     */
    public static function activate() {
        self::instance()->register_role();
    }

    /**
     * Register installment_customer role with customer capabilities.
     */
    public function register_role() {
        if (!function_exists('get_role') || !function_exists('add_role')) {
            return;
        }

        $customer_role = get_role('customer');
        $caps = ($customer_role && !empty($customer_role->capabilities)) ? $customer_role->capabilities : array('read' => true);
        $caps['use_vamariz_installments'] = true;

        $existing_role = get_role(self::ROLE_SLUG);
        if (!$existing_role) {
            add_role(self::ROLE_SLUG, self::ROLE_NAME, $caps);
        } else {
            foreach ($caps as $cap => $grant) {
                if ($grant && !$existing_role->has_cap($cap)) {
                    $existing_role->add_cap($cap);
                }
            }
        }
    }

    /**
     * Check if a given user (or current user) is an installment customer.
     *
     * @param int|null $user_id
     * @return bool
     */
    public static function is_installment_customer($user_id = null) {
        if (!function_exists('is_user_logged_in') || !function_exists('wp_get_current_user')) {
            return false;
        }

        if ($user_id === null) {
            if (!is_user_logged_in()) {
                return false;
            }
            $user = wp_get_current_user();
        } else {
            $user_id = intval($user_id);
            if ($user_id <= 0) {
                return false;
            }
            if (!function_exists('get_userdata')) {
                return false;
            }
            $user = get_userdata($user_id);
        }

        if (!$user || empty($user->roles)) {
            return false;
        }

        // Administrators can always preview and test installment capabilities
        if (in_array('administrator', $user->roles, true)) {
            return true;
        }

        return in_array(self::ROLE_SLUG, $user->roles, true);
    }

    /**
     * Render role assignment toggle in user edit screen.
     */
    public function render_user_profile_fields($user) {
        if (!function_exists('current_user_can') || (!current_user_can('manage_woocommerce') && !current_user_can('promote_users'))) {
            return;
        }

        $is_installment = in_array(self::ROLE_SLUG, (array) $user->roles, true);
        wp_nonce_field('vamariz_save_user_role', 'vamariz_user_role_nonce');
        ?>
        <h3 style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd;"><?php esc_html_e('خرید اقساطی واماریز', 'deposits-partial-payments-for-woocommerce'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="vamariz_is_installment_customer"><?php esc_html_e('وضعیت مشتری اقساطی', 'deposits-partial-payments-for-woocommerce'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="vamariz_is_installment_customer" id="vamariz_is_installment_customer" value="1" <?php checked($is_installment, true); ?>>
                        <strong><?php esc_html_e('فعال بودن دسترسی به خرید اقساطی ۴ ماهه واماریز', 'deposits-partial-payments-for-woocommerce'); ?></strong>
                    </label>
                    <p class="description"><?php esc_html_e('با انتخاب این گزینه، نقش این کاربر به «مشتری اقساطی» ارتقا یافته و ویجت و شرایط اقساط واماریز برای وی نمایش داده می‌شود.', 'deposits-partial-payments-for-woocommerce'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save user role toggle from profile.
     */
    public function save_user_profile_fields($user_id) {
        if (!function_exists('current_user_can') || (!current_user_can('manage_woocommerce') && !current_user_can('promote_users'))) {
            return;
        }

        if (!isset($_POST['vamariz_user_role_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vamariz_user_role_nonce'])), 'vamariz_save_user_role')) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $should_be_installment = !empty($_POST['vamariz_is_installment_customer']);
        $has_role = in_array(self::ROLE_SLUG, (array) $user->roles, true);

        if ($should_be_installment && !$has_role) {
            $user->add_role(self::ROLE_SLUG);
        } elseif (!$should_be_installment && $has_role) {
            $user->remove_role(self::ROLE_SLUG);
            // Ensure user retains customer role if they have no other role
            if (empty($user->roles)) {
                $user->add_role('customer');
            }
        }
    }

    /**
     * Capture invite token into cookie if present in URL, or redeem immediately if user is logged in.
     */
    public function capture_invite_cookie() {
        $token = '';
        if (!empty($_GET['vamariz_invite'])) {
            $token = sanitize_text_field(wp_unslash($_GET['vamariz_invite']));
        } elseif (!empty($_COOKIE['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_COOKIE['vamariz_invite_token']));
        }

        if (empty($token)) {
            return;
        }

        $invite = self::get_invite($token);
        if (!$invite || $invite['status'] !== 'pending') {
            return;
        }

        // 1. If user is already logged in, immediately activate their installment access
        if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('get_current_user_id')) {
            $user_id = get_current_user_id();
            if ($user_id > 0 && function_exists('get_userdata')) {
                $user = get_userdata($user_id);
                if ($user) {
                    if (!in_array(self::ROLE_SLUG, (array) $user->roles, true)) {
                        $user->add_role(self::ROLE_SLUG);
                    }
                    self::mark_invite_used($token, $user_id);
                    // Clear cookie if set
                    if (!headers_sent()) {
                        $cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
                        $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
                        setcookie('vamariz_invite_token', '', time() - 3600, $cookie_path, $cookie_domain);
                    }
                    return;
                }
            }
        }

        // 2. If guest, preserve token in cookie for registration / checkout
        if (!empty($_GET['vamariz_invite']) && !headers_sent()) {
            $cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
            $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
            setcookie('vamariz_invite_token', $token, time() + 86400 * 3, $cookie_path, $cookie_domain, is_ssl(), true);
        }
    }

    /**
     * Render invite information in WooCommerce registration and checkout forms.
     */
    public function render_invite_registration_fields() {
        static $rendered = false;
        if ($rendered) {
            return;
        }

        $token = '';
        if (!empty($_GET['vamariz_invite'])) {
            $token = sanitize_text_field(wp_unslash($_GET['vamariz_invite']));
        } elseif (!empty($_POST['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['vamariz_invite_token']));
        } elseif (!empty($_COOKIE['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_COOKIE['vamariz_invite_token']));
        }

        if (empty($token)) {
            return;
        }

        $invite = self::get_invite($token);
        if (!$invite || $invite['status'] !== 'pending') {
            return;
        }

        $rendered = true;
        echo '<div class="woocommerce-message" style="background:#f4fbf7; border-color:#2ecc71; color:#27ae60; font-size:14px; margin-bottom:15px; padding:12px 15px; border-radius:6px;">'
            . sprintf(
                esc_html__('سلام %s عزیز! شما با دعوت‌نامه اختصاصی واماریز وارد شده‌اید. پس از ثبت‌نام، حساب شما بلافاصله به عنوان «مشتری اقساطی» فعال خواهد شد.', 'deposits-partial-payments-for-woocommerce'),
                '<strong>' . esc_html($invite['name']) . '</strong>'
            )
            . '</div>';
        echo '<input type="hidden" name="vamariz_invite_token" value="' . esc_attr($token) . '" />';
    }

    /**
     * Automatically assign installment_customer role on registration with valid invite token.
     */
    public function handle_customer_registration_with_invite($customer_id, $new_customer_data = array(), $password_generated = false) {
        $token = '';
        if (!empty($_POST['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['vamariz_invite_token']));
        } elseif (!empty($_GET['vamariz_invite'])) {
            $token = sanitize_text_field(wp_unslash($_GET['vamariz_invite']));
        } elseif (!empty($_COOKIE['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_COOKIE['vamariz_invite_token']));
        }

        if (empty($token)) {
            return;
        }

        $invite = self::get_invite($token);
        if ($invite && $invite['status'] === 'pending') {
            $user = function_exists('get_userdata') ? get_userdata($customer_id) : false;
            if ($user) {
                self::mark_invite_used($token, $customer_id);
                $user->add_role(self::ROLE_SLUG);
                // Clear cookie
                if (!headers_sent()) {
                    $cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
                    $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
                    setcookie('vamariz_invite_token', '', time() - 3600, $cookie_path, $cookie_domain);
                }
            }
        }
    }

    /**
     * Automatically assign installment_customer role when user logs in with active invite token.
     */
    public function handle_user_login_with_invite($user_login, $user) {
        $token = '';
        if (!empty($_GET['vamariz_invite'])) {
            $token = sanitize_text_field(wp_unslash($_GET['vamariz_invite']));
        } elseif (!empty($_COOKIE['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_COOKIE['vamariz_invite_token']));
        }

        if (empty($token) || !is_object($user) || empty($user->ID)) {
            return;
        }

        $invite = self::get_invite($token);
        if ($invite && $invite['status'] === 'pending') {
            self::mark_invite_used($token, $user->ID);
            if (!in_array(self::ROLE_SLUG, (array) $user->roles, true)) {
                $user->add_role(self::ROLE_SLUG);
            }
            if (!headers_sent()) {
                $cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
                $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
                setcookie('vamariz_invite_token', '', time() - 3600, $cookie_path, $cookie_domain);
            }
        }
    }

    /**
     * Generate a new invitation token.
     *
     * @param string $name Customer full name.
     * @param string $phone Customer mobile phone.
     * @return array Created invite details.
     */
    public static function create_invite($name, $phone) {
        $invites = get_option(self::OPTION_INVITES, array());
        if (!is_array($invites)) {
            $invites = array();
        }

        $token = 'vm_' . wp_generate_password(16, false);
        $invite = array(
            'token'      => $token,
            'name'       => sanitize_text_field($name),
            'phone'      => sanitize_text_field($phone),
            'status'     => 'pending',
            'created_at' => current_time('timestamp'),
            'used_by'    => 0,
            'used_at'    => 0,
        );

        $invites[$token] = $invite;
        update_option(self::OPTION_INVITES, $invites);

        return $invite;
    }

    /**
     * Get invite details by token.
     */
    public static function get_invite($token) {
        $invites = get_option(self::OPTION_INVITES, array());
        return isset($invites[$token]) ? $invites[$token] : null;
    }

    /**
     * Mark invite as used.
     */
    public static function mark_invite_used($token, $user_id) {
        $invites = get_option(self::OPTION_INVITES, array());
        if (isset($invites[$token])) {
            $invites[$token]['status']  = 'used';
            $invites[$token]['used_by'] = $user_id;
            $invites[$token]['used_at'] = current_time('timestamp');
            update_option(self::OPTION_INVITES, $invites);
        }
    }

    /**
     * Revoke an invite.
     */
    public static function revoke_invite($token) {
        $invites = get_option(self::OPTION_INVITES, array());
        if (isset($invites[$token])) {
            $invites[$token]['status'] = 'revoked';
            update_option(self::OPTION_INVITES, $invites);
            return true;
        }
        return false;
    }

    /**
     * Get all invites.
     */
    public static function get_all_invites() {
        $invites = get_option(self::OPTION_INVITES, array());
        return is_array($invites) ? $invites : array();
    }

    /**
     * Get invite URL.
     */
    public static function get_invite_url($token) {
        $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '';
        if (!$account_url) {
            $account_url = function_exists('home_url') ? home_url('/my-account/') : '/my-account/';
        }
        return add_query_arg('vamariz_invite', $token, $account_url);
    }
}
