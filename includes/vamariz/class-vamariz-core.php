<?php
/**
 * Vamariz Core Orchestrator
 *
 * @package VamarizInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-vamariz-roles.php';
require_once __DIR__ . '/class-vamariz-sms.php';
require_once __DIR__ . '/class-vamariz-snapppay.php';
require_once __DIR__ . '/class-vamariz-installments.php';
require_once __DIR__ . '/class-vamariz-dashboard.php';

class Vamariz_Core {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        Vamariz_Roles::instance();
        Vamariz_SMS::instance();
        Vamariz_SnappPay::instance();
        Vamariz_Installments::instance();
        if (is_admin()) {
            Vamariz_Dashboard::instance();
            if (!class_exists('WooCommerce')) {
                add_action('admin_notices', array($this, 'render_woocommerce_missing_notice'));
            }
        }
    }

    public function render_woocommerce_missing_notice() {
        echo '<div class="notice notice-warning is-dismissible"><p>' .
            esc_html__('افزونه خرید اقساطی ۴ ماهه واماریز برای فعالیت کامل نیازمند فعال بودن ووکامرس است. لطفاً افزونه ووکامرس را فعال نمایید.', 'deposits-partial-payments-for-woocommerce') .
            '</p></div>';
    }
}
