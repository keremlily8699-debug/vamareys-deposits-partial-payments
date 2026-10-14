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

        // Registration with invite token
        add_action('woocommerce_register_form', array($this, 'render_invite_registration_fields'));
        add_action('woocommerce_created_customer', array($this, 'handle_customer_registration_with_invite'), 10, 3);
        add_action('template_redirect', array($this, 'capture_invite_cookie'));
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

        if (!get_role(self::ROLE_SLUG)) {
            $customer_role = get_role('customer');
            $caps = ($customer_role && !empty($customer_role->capabilities)) ? $customer_role->capabilities : array('read' => true);
            $caps['use_vamariz_installments'] = true;
            add_role(self::ROLE_SLUG, self::ROLE_NAME, $caps);
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

        if (!$user_id) {
            if (!is_user_logged_in()) {
                return false;
            }
            $user = wp_get_current_user();
        } else {
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
        if (!current_user_can('manage_woocommerce') && !current_user_can('promote_users')) {
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
        if (!current_user_can('manage_woocommerce') && !current_user_can('promote_users')) {
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
     * Capture invite token into cookie if present in URL.
     */
    public function capture_invite_cookie() {
        if (!empty($_GET['vamariz_invite'])) {
            $token = sanitize_text_field(wp_unslash($_GET['vamariz_invite']));
            $invite = self::get_invite($token);
            if ($invite && $invite['status'] === 'pending') {
                setcookie('vamariz_invite_token', $token, time() + 86400 * 3, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            }
        }
    }

    /**
     * Render invite information in WooCommerce registration form.
     */
    public function render_invite_registration_fields() {
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
    public function handle_customer_registration_with_invite($customer_id, $new_customer_data, $password_generated) {
        $token = '';
        if (!empty($_POST['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['vamariz_invite_token']));
        } elseif (!empty($_COOKIE['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_COOKIE['vamariz_invite_token']));
        }

        if (empty($token)) {
            return;
        }

        $invite = self::get_invite($token);
        if ($invite && $invite['status'] === 'pending') {
            $user = get_userdata($customer_id);
            if ($user) {
                $user->add_role(self::ROLE_SLUG);
                self::mark_invite_used($token, $customer_id);
                // Clear cookie
                setcookie('vamariz_invite_token', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
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
        $account_url = wc_get_page_permalink('myaccount');
        if (!$account_url) {
            $account_url = home_url('/my-account/');
        }
        return add_query_arg('vamariz_invite', $token, $account_url);
    }
}
