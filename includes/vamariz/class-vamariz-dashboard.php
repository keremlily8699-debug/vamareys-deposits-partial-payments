<?php
/**
 * Vamariz Admin Dashboard & Tracking Center
 *
 * @package VamarizInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_Dashboard {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
    }

    public function register_admin_menu() {
        add_menu_page(
            'اقساط واماریز',
            'اقساط واماریز',
            'manage_woocommerce',
            'vamariz-installments',
            array($this, 'render_dashboard_page'),
            'dashicons-calendar-alt',
            56
        );
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'vamariz-installments') === false) {
            return;
        }

        wp_enqueue_style(
            'vamariz-backend-css',
            plugins_url('assets/css/vamariz-backend.css', dirname(dirname(__FILE__))),
            array(),
            AWCDP_VERSION
        );

        wp_enqueue_script(
            'vamariz-backend-js',
            plugins_url('assets/js/vamariz-backend.js', dirname(dirname(__FILE__))),
            array('jquery'),
            AWCDP_VERSION,
            true
        );
    }

    /**
     * Handle actions such as creating invites, manual SMS, testing SMS, and saving settings.
     */
    public function handle_admin_actions() {
        if (!function_exists('current_user_can') || !current_user_can('manage_woocommerce')) {
            return;
        }

        // 1. Create Invite
        if (isset($_POST['vamariz_create_invite_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vamariz_create_invite_nonce'])), 'vamariz_create_invite_action')) {
            $name  = sanitize_text_field(wp_unslash($_POST['invite_name']));
            $phone = sanitize_text_field(wp_unslash($_POST['invite_phone']));
            $send_sms = !empty($_POST['send_invite_sms']);

            if (!empty($name) && !empty($phone)) {
                $invite = Vamariz_Roles::create_invite($name, $phone);
                if ($send_sms) {
                    $invite_url = Vamariz_Roles::get_invite_url($invite['token']);
                    Vamariz_SMS::send_invite_sms($phone, $name, $invite_url);
                }
                wp_safe_redirect(add_query_arg(array('page' => 'vamariz-installments', 'tab' => 'invitations', 'msg' => 'invite_created'), admin_url('admin.php')));
                exit;
            }
        }

        // 2. Revoke Invite
        if (isset($_GET['action'], $_GET['token'], $_GET['_wpnonce']) && $_GET['action'] === 'revoke_invite' && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'vamariz_revoke_nonce')) {
            $token = sanitize_text_field(wp_unslash($_GET['token']));
            Vamariz_Roles::revoke_invite($token);
            wp_safe_redirect(add_query_arg(array('page' => 'vamariz-installments', 'tab' => 'invitations', 'msg' => 'invite_revoked'), admin_url('admin.php')));
            exit;
        }

        // 3. Manual Reminder SMS
        if (isset($_GET['action'], $_GET['sub_order_id'], $_GET['_wpnonce']) && $_GET['action'] === 'send_manual_sms' && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'vamariz_sms_nonce')) {
            $sub_id = intval($_GET['sub_order_id']);
            $res = Vamariz_SMS::send_installment_reminder($sub_id, 0);
            $msg = !empty($res['success']) ? 'sms_sent' : 'sms_failed';
            wp_safe_redirect(add_query_arg(array('page' => 'vamariz-installments', 'tab' => 'orders', 'msg' => $msg), admin_url('admin.php')));
            exit;
        }

        // 4. Save Settings
        if (isset($_POST['vamariz_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vamariz_settings_nonce'])), 'vamariz_save_settings_action')) {
            $api_url = function_exists('esc_url_raw') ? esc_url_raw(wp_unslash($_POST['vamariz_sms_api_url'])) : sanitize_text_field(wp_unslash($_POST['vamariz_sms_api_url']));
            update_option('vamariz_sms_api_url', $api_url);
            update_option('vamariz_sms_from_number', sanitize_text_field(wp_unslash($_POST['vamariz_sms_from_number'])));
            update_option('vamariz_min_installment_amount', max(0, floatval(wp_unslash($_POST['vamariz_min_installment_amount']))));
            update_option('vamariz_max_active_orders', max(1, intval(wp_unslash($_POST['vamariz_max_active_orders']))));

            wp_safe_redirect(add_query_arg(array('page' => 'vamariz-installments', 'tab' => 'settings', 'msg' => 'settings_saved'), admin_url('admin.php')));
            exit;
        }

        // 5. Test SMS
        if (isset($_POST['vamariz_test_sms_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vamariz_test_sms_nonce'])), 'vamariz_test_sms_action')) {
            $test_phone = sanitize_text_field(wp_unslash($_POST['test_phone']));
            $res = Vamariz_SMS::send_sms($test_phone, "تست اتصال سامانه ملی‌پیامک در سیستم اقساط واماریز با موفقیت انجام شد.");
            $msg = !empty($res['success']) ? 'test_sms_ok' : 'test_sms_fail';
            wp_safe_redirect(add_query_arg(array('page' => 'vamariz-installments', 'tab' => 'settings', 'msg' => $msg), admin_url('admin.php')));
            exit;
        }
    }

    /**
     * Render the admin dashboard view.
     */
    public function render_dashboard_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'orders';
        ?>
        <div class="wrap vamariz-admin-wrap" dir="rtl">
            <div class="vamariz-admin-header">
                <div class="vamariz-brand">
                    <span class="vamariz-badge-logo">V</span>
                    <div>
                        <h1><?php esc_html_e('سیستم مدیریت اقساط واماریز', 'deposits-partial-payments-for-woocommerce'); ?></h1>
                        <p class="description"><?php esc_html_e('پایش فروش‌های اقساطی ۴ ماهه، ارسال یادآورها و مدیریت مشتریان تاییدشده', 'deposits-partial-payments-for-woocommerce'); ?></p>
                    </div>
                </div>
            </div>

            <?php $this->render_admin_notices(); ?>

            <nav class="nav-tab-wrapper vamariz-nav-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=orders')); ?>" class="nav-tab <?php echo $active_tab === 'orders' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span> <?php esc_html_e('سفارشات و اقساط', 'deposits-partial-payments-for-woocommerce'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=invitations')); ?>" class="nav-tab <?php echo $active_tab === 'invitations' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-email-alt"></span> <?php esc_html_e('صدور دعوت‌نامه‌ها', 'deposits-partial-payments-for-woocommerce'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=settings')); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('تنظیمات ملی‌پیامک و سیستم', 'deposits-partial-payments-for-woocommerce'); ?>
                </a>
            </nav>

            <div class="vamariz-tab-content" style="margin-top: 20px;">
                <?php
                switch ($active_tab) {
                    case 'invitations':
                        $this->render_invitations_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'orders':
                    default:
                        $this->render_orders_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_admin_notices() {
        if (!isset($_GET['msg'])) {
            return;
        }
        $msg = sanitize_text_field(wp_unslash($_GET['msg']));
        if ($msg === 'invite_created') {
            echo '<div class="notice notice-success is-dismissible"><p>دعوت‌نامه با موفقیت صادر شد و پیامک آن برای مشتری ارسال گردید.</p></div>';
        } elseif ($msg === 'invite_revoked') {
            echo '<div class="notice notice-info is-dismissible"><p>دعوت‌نامه با موفقیت باطل شد.</p></div>';
        } elseif ($msg === 'sms_sent') {
            echo '<div class="notice notice-success is-dismissible"><p>پیامک یادآوری قسط با موفقیت ارسال شد.</p></div>';
        } elseif ($msg === 'sms_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>خطا در ارسال پیامک. لطفاً تنظیمات ملی‌پیامک را بررسی کنید.</p></div>';
        } elseif ($msg === 'settings_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>تنظیمات با موفقیت ذخیره شد.</p></div>';
        } elseif ($msg === 'test_sms_ok') {
            echo '<div class="notice notice-success is-dismissible"><p>پیامک تستی ملی‌پیامک با موفقیت تحویل داده شد.</p></div>';
        } elseif ($msg === 'test_sms_fail') {
            echo '<div class="notice notice-error is-dismissible"><p>ارسال پیامک تستی با شکست مواجه شد. خطای سامانه را بررسی فرمایید.</p></div>';
        }
    }

    /**
     * Tab 1: Orders and Installments Matrix
     */
    private function render_orders_tab() {
        if (!function_exists('wc_get_orders')) {
            echo '<div class="vamariz-panel"><p>' . esc_html__('ووکامرس فعال نیست. برای مشاهده سفارشات اقساطی، لطفاً ووکامرس را فعال کنید.', 'deposits-partial-payments-for-woocommerce') . '</p></div>';
            return;
        }

        // Query installment parent orders
        $orders = wc_get_orders(array(
            'limit'      => 50,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_key'   => '_vamariz_is_installment_order',
            'meta_value' => 'yes',
        ));

        // Calculate KPI totals
        $total_receivable = 0;
        $total_collected  = 0;
        $overdue_count    = 0;
        $now = current_time('timestamp');

        foreach ($orders as $o) {
            $is_cancelled = false;
            if (method_exists($o, 'has_status') && $o->has_status(array('cancelled', 'refunded', 'failed', 'trash'))) {
                $is_cancelled = true;
            } elseif (method_exists($o, 'get_status') && in_array($o->get_status(), array('cancelled', 'refunded', 'failed', 'trash', 'wc-cancelled', 'wc-refunded', 'wc-failed'), true)) {
                $is_cancelled = true;
            }
            if ($is_cancelled) {
                continue;
            }
            $total_receivable += $o->get_total();
            // Installment 1 is collected
            $total_collected += round($o->get_total() / 4, 2);

            $child_ids = $o->get_meta('_vamariz_child_suborder_ids', true);
            if (is_array($child_ids)) {
                foreach ($child_ids as $sub_id) {
                    $sub = wc_get_order($sub_id);
                    if ($sub) {
                        if ($sub->is_paid()) {
                            $total_collected += $sub->get_total();
                        } else {
                            $due = intval($sub->get_meta('_vamariz_due_date', true));
                            if ($due > 0 && $due < $now) {
                                $overdue_count++;
                            }
                        }
                    }
                }
            }
        }
        ?>
        <!-- KPI Cards -->
        <div class="vamariz-kpi-grid">
            <div class="vamariz-kpi-card">
                <span class="vamariz-kpi-title"><?php esc_html_e('کل فروش‌های اقساطی', 'deposits-partial-payments-for-woocommerce'); ?></span>
                <span class="vamariz-kpi-value"><?php echo esc_html(number_format($total_receivable)); ?> <small>تومان</small></span>
                <span class="vamariz-kpi-sub"><?php echo sprintf(esc_html__('%d سفارش ثبت‌شده', 'deposits-partial-payments-for-woocommerce'), count($orders)); ?></span>
            </div>
            <div class="vamariz-kpi-card vamariz-kpi-green">
                <span class="vamariz-kpi-title"><?php esc_html_e('مجموع اقساط وصول‌شده', 'deposits-partial-payments-for-woocommerce'); ?></span>
                <span class="vamariz-kpi-value"><?php echo esc_html(number_format($total_collected)); ?> <small>تومان</small></span>
                <span class="vamariz-kpi-sub"><?php esc_html_e('پیش‌پرداخت‌ها و اقساط تسویه‌شده', 'deposits-partial-payments-for-woocommerce'); ?></span>
            </div>
            <div class="vamariz-kpi-card vamariz-kpi-orange">
                <span class="vamariz-kpi-title"><?php esc_html_e('مانده مطالبات آتی', 'deposits-partial-payments-for-woocommerce'); ?></span>
                <span class="vamariz-kpi-value"><?php echo esc_html(number_format(max(0, $total_receivable - $total_collected))); ?> <small>تومان</small></span>
                <span class="vamariz-kpi-sub"><?php esc_html_e('اقساط در نوبت سررسید', 'deposits-partial-payments-for-woocommerce'); ?></span>
            </div>
            <div class="vamariz-kpi-card vamariz-kpi-red">
                <span class="vamariz-kpi-title"><?php esc_html_e('اقساط معوقه و دارای تاخیر', 'deposits-partial-payments-for-woocommerce'); ?></span>
                <span class="vamariz-kpi-value"><?php echo esc_html($overdue_count); ?> <small>مورد</small></span>
                <span class="vamariz-kpi-sub"><?php esc_html_e('نیازمند پیگیری تلفنی / پیامکی', 'deposits-partial-payments-for-woocommerce'); ?></span>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="vamariz-panel">
            <div class="vamariz-panel-header">
                <h2><?php esc_html_e('جدول ماتریسی وضعیت اقساط ۴ ماهه', 'deposits-partial-payments-for-woocommerce'); ?></h2>
            </div>
            <table class="wp-list-table widefat fixed striped vamariz-table">
                <thead>
                    <tr>
                        <th style="width: 90px;"><?php esc_html_e('سفارش', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th style="width: 140px;"><?php esc_html_e('مشتری', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th style="width: 110px;"><?php esc_html_e('تماس', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th style="width: 120px;"><?php esc_html_e('مبلغ کل', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('قسط ۱ (۲۵٪)', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('قسط ۲ (۲۵٪)', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('قسط ۳ (۲۵٪)', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('قسط ۴ (۲۵٪)', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th style="width: 110px;"><?php esc_html_e('وضعیت کلی', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th style="width: 130px;"><?php esc_html_e('عملیات', 'deposits-partial-payments-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="10" style="text-align: center; padding: 25px;"><?php esc_html_e('هنوز هیچ سفارش اقساطی ثبت نشده است.', 'deposits-partial-payments-for-woocommerce'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $child_ids = $order->get_meta('_vamariz_child_suborder_ids', true);
                            $inst_amount = round($order->get_total() / 4);
                            $edit_url = method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post=' . $order->get_id() . '&action=edit');
                            $customer_name = method_exists($order, 'get_formatted_billing_full_name') ? $order->get_formatted_billing_full_name() : trim($order->get_billing_first_name() . ' ' . (method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : ''));
                            if (empty($customer_name)) {
                                $customer_name = 'مشتری';
                            }
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url($edit_url); ?>" class="vamariz-order-link">
                                        <strong>#<?php echo esc_html($order->get_order_number()); ?></strong>
                                    </a>
                                    <br><small><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date('Y/m/d') : ''); ?></small>
                                </td>
                                <td><?php echo esc_html($customer_name); ?></td>
                                <td><a href="tel:<?php echo esc_attr($order->get_billing_phone()); ?>"><?php echo esc_html($order->get_billing_phone()); ?></a></td>
                                <td><strong><?php echo esc_html(number_format($order->get_total())); ?></strong> تومان</td>

                                <!-- Installment 1 (Paid at checkout) -->
                                <td>
                                    <span class="vamariz-badge vamariz-badge-paid">
                                        ✓ وصول شد (<?php echo esc_html(number_format($inst_amount)); ?>)
                                    </span>
                                </td>

                                <!-- Installments 2, 3, 4 -->
                                <?php for ($i = 2; $i <= 4; $i++): ?>
                                    <td>
                                        <?php
                                        $sub_id = isset($child_ids[$i]) ? $child_ids[$i] : 0;
                                        $sub = $sub_id ? wc_get_order($sub_id) : false;
                                        $sub_cancelled = false;
                                        if (method_exists($order, 'has_status') && $order->has_status(array('cancelled', 'refunded', 'failed', 'trash'))) {
                                            $sub_cancelled = true;
                                        } elseif (method_exists($order, 'get_status') && in_array($order->get_status(), array('cancelled', 'refunded', 'failed', 'trash', 'wc-cancelled', 'wc-refunded', 'wc-failed'), true)) {
                                            $sub_cancelled = true;
                                        } elseif ($sub && method_exists($sub, 'has_status') && $sub->has_status(array('cancelled', 'refunded', 'failed', 'trash'))) {
                                            $sub_cancelled = true;
                                        } elseif ($sub && method_exists($sub, 'get_status') && in_array($sub->get_status(), array('cancelled', 'refunded', 'failed', 'trash', 'wc-cancelled', 'wc-refunded', 'wc-failed'), true)) {
                                            $sub_cancelled = true;
                                        }
                                        if (!$sub):
                                            echo '<span class="vamariz-badge vamariz-badge-future">-</span>';
                                        elseif ($sub_cancelled):
                                            echo '<span class="vamariz-badge vamariz-badge-future">لغو شده</span>';
                                        elseif (method_exists($sub, 'is_paid') && $sub->is_paid()):
                                            echo '<span class="vamariz-badge vamariz-badge-paid">✓ پرداخت شد</span>';
                                        else:
                                            $due = intval($sub->get_meta('_vamariz_due_date', true));
                                            if ($due <= 0):
                                                echo '<span class="vamariz-badge vamariz-badge-future">-</span>';
                                            else:
                                                $diff = round(($due - $now) / 86400);
                                                if ($diff < 0):
                                                    echo '<span class="vamariz-badge vamariz-badge-overdue">معوق (' . abs($diff) . ' روز تاخیر)</span>';
                                                elseif ($diff <= 3):
                                                    echo '<span class="vamariz-badge vamariz-badge-warning">' . $diff . ' روز تا موعد</span>';
                                                else:
                                                    echo '<span class="vamariz-badge vamariz-badge-future">' . date('m/d', $due) . '</span>';
                                                endif;
                                            endif;
                                            ?>
                                            <div style="margin-top: 4px;">
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=vamariz-installments&action=send_manual_sms&sub_order_id=' . $sub->get_id()), 'vamariz_sms_nonce')); ?>" class="button button-small" title="ارسال پیامک یادآور">
                                                    پیامک
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>

                                <td>
                                    <span class="order-status status-<?php echo esc_attr($order->get_status()); ?>">
                                        <?php echo esc_html(function_exists('wc_get_order_status_name') ? wc_get_order_status_name($order->get_status()) : $order->get_status()); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($edit_url); ?>" class="button button-primary button-small">
                                        مدیریت سفارش
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Tab 2: Manage and Issue Invitations
     */
    private function render_invitations_tab() {
        $invites = Vamariz_Roles::get_all_invites();
        ?>
        <div class="vamariz-panel">
            <div class="vamariz-panel-header">
                <h2><?php esc_html_e('صدور دعوت‌نامه جدید برای مشتری معتمد', 'deposits-partial-payments-for-woocommerce'); ?></h2>
                <p><?php esc_html_e('با ثبت مشخصات زیر، یک توکن امن یک‌بارمصرف تولید شده و پیامک لینک ثبت‌نام به شماره مشتری ارسال می‌شود.', 'deposits-partial-payments-for-woocommerce'); ?></p>
            </div>

            <form method="post" action="" class="vamariz-invite-form" style="display: flex; gap: 15px; align-items: flex-end; padding: 15px 0;">
                <?php wp_nonce_field('vamariz_create_invite_action', 'vamariz_create_invite_nonce'); ?>
                <div>
                    <label><strong><?php esc_html_e('نام و نام خانوادگی:', 'deposits-partial-payments-for-woocommerce'); ?></strong></label><br>
                    <input type="text" name="invite_name" required placeholder="مثال: علی احمدی" class="regular-text" style="width: 200px;">
                </div>
                <div>
                    <label><strong><?php esc_html_e('شماره موبایل:', 'deposits-partial-payments-for-woocommerce'); ?></strong></label><br>
                    <input type="text" name="invite_phone" required placeholder="09123456789" class="regular-text" style="width: 170px;">
                </div>
                <div style="padding-bottom: 5px;">
                    <label>
                        <input type="checkbox" name="send_invite_sms" value="1" checked>
                        <?php esc_html_e('ارسال خودکار پیامک حاوی لینک دعوت از طریق ملی‌پیامک', 'deposits-partial-payments-for-woocommerce'); ?>
                    </label>
                </div>
                <div>
                    <button type="submit" class="button button-primary"><?php esc_html_e('صدور و ارسال دعوت‌نامه', 'deposits-partial-payments-for-woocommerce'); ?></button>
                </div>
            </form>
        </div>

        <div class="vamariz-panel" style="margin-top: 25px;">
            <div class="vamariz-panel-header">
                <h2><?php esc_html_e('لیست دعوت‌نامه‌های صادرشده', 'deposits-partial-payments-for-woocommerce'); ?></h2>
            </div>
            <table class="wp-list-table widefat fixed striped vamariz-table">
                <thead>
                    <tr>
                        <th style="width: 120px;"><?php esc_html_e('توکن', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('نام مخاطب', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('شماره تماس', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('تاریخ صدور', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('وضعیت', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th><?php esc_html_e('کاربر متناظر', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th style="width: 250px;"><?php esc_html_e('لینک اختصاصی دعوت', 'deposits-partial-payments-for-woocommerce'); ?></th>
                        <th style="width: 90px;"><?php esc_html_e('عملیات', 'deposits-partial-payments-for-woocommerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invites)): ?>
                        <tr><td colspan="8" style="text-align: center; padding: 20px;"><?php esc_html_e('هیچ دعوت‌نامه‌ای صادر نشده است.', 'deposits-partial-payments-for-woocommerce'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach (array_reverse($invites) as $inv): ?>
                            <?php $invite_url = Vamariz_Roles::get_invite_url($inv['token']); ?>
                            <tr>
                                <td><code><?php echo esc_html(substr($inv['token'], 0, 10)); ?>...</code></td>
                                <td><strong><?php echo esc_html($inv['name']); ?></strong></td>
                                <td><?php echo esc_html($inv['phone']); ?></td>
                                <td><?php echo esc_html(date('Y/m/d H:i', $inv['created_at'])); ?></td>
                                <td>
                                    <?php if ($inv['status'] === 'used'): ?>
                                        <span class="vamariz-badge vamariz-badge-paid">استفاده شد</span>
                                    <?php elseif ($inv['status'] === 'revoked'): ?>
                                        <span class="vamariz-badge vamariz-badge-overdue">باطل شده</span>
                                    <?php else: ?>
                                        <span class="vamariz-badge vamariz-badge-warning">در انتظار ثبت‌نام</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($inv['used_by'])) {
                                        $u = get_userdata($inv['used_by']);
                                        echo $u ? esc_html($u->display_name . ' (' . $u->user_login . ')') : '-';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <input type="text" readonly value="<?php echo esc_url($invite_url); ?>" style="width: 180px; font-size: 11px;">
                                    <button type="button" class="button button-small vamariz-copy-btn" data-url="<?php echo esc_url($invite_url); ?>">کپی</button>
                                </td>
                                <td>
                                    <?php if ($inv['status'] === 'pending'): ?>
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=vamariz-installments&action=revoke_invite&token=' . $inv['token']), 'vamariz_revoke_nonce')); ?>" class="button button-link-delete button-small" onclick="return confirm('آیا از ابطال این دعوت‌نامه اطمینان دارید؟');">
                                            ابطال
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Tab 3: Melipayamak & System Settings
     */
    private function render_settings_tab() {
        $api_url = get_option('vamariz_sms_api_url', Vamariz_SMS::DEFAULT_API_URL);
        $from    = get_option('vamariz_sms_from_number', Vamariz_SMS::DEFAULT_FROM);
        $min_amt = get_option('vamariz_min_installment_amount', 0);
        $max_ord = get_option('vamariz_max_active_orders', 3);
        ?>
        <div style="display: flex; gap: 20px;">
            <div class="vamariz-panel" style="flex: 2;">
                <div class="vamariz-panel-header">
                    <h2><?php esc_html_e('تنظیمات ملی‌پیامک و محدودیت‌های اقساط واماریز', 'deposits-partial-payments-for-woocommerce'); ?></h2>
                </div>
                <form method="post" action="">
                    <?php wp_nonce_field('vamariz_save_settings_action', 'vamariz_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="vamariz_sms_api_url"><?php esc_html_e('آدرس وب‌سرویس ملی‌پیامک (REST Simple):', 'deposits-partial-payments-for-woocommerce'); ?></label></th>
                            <td>
                                <input type="url" name="vamariz_sms_api_url" id="vamariz_sms_api_url" value="<?php echo esc_attr($api_url); ?>" class="large-text" required dir="ltr">
                                <p class="description"><?php esc_html_e('نشانی کامل اندپوینت ارسال ساده ملی‌پیامک به همراه توکن اختصاصی شما', 'deposits-partial-payments-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="vamariz_sms_from_number"><?php esc_html_e('شماره فرستنده ملی‌پیامک (From):', 'deposits-partial-payments-for-woocommerce'); ?></label></th>
                            <td>
                                <input type="text" name="vamariz_sms_from_number" id="vamariz_sms_from_number" value="<?php echo esc_attr($from); ?>" class="regular-text" required dir="ltr">
                                <p class="description"><?php esc_html_e('شماره خط اختصاصی پنل ملی‌پیامک شما (مثال: 50004001...)', 'deposits-partial-payments-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="vamariz_min_installment_amount"><?php esc_html_e('حداقل مبلغ سفارش برای اقساط (تومان):', 'deposits-partial-payments-for-woocommerce'); ?></label></th>
                            <td>
                                <input type="number" name="vamariz_min_installment_amount" id="vamariz_min_installment_amount" value="<?php echo esc_attr($min_amt); ?>" class="regular-text" min="0" step="100000">
                                <p class="description"><?php esc_html_e('برای سفارش‌های کمتر از این مبلغ امکان خرید اقساطی وجود نخواهد داشت (0 به معنای بدون محدودیت).', 'deposits-partial-payments-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="vamariz_max_active_orders"><?php esc_html_e('حداکثر سفارش‌های اقساطی فعال همزمان:', 'deposits-partial-payments-for-woocommerce'); ?></label></th>
                            <td>
                                <input type="number" name="vamariz_max_active_orders" id="vamariz_max_active_orders" value="<?php echo esc_attr($max_ord); ?>" class="small-text" min="1" max="10">
                                <p class="description"><?php esc_html_e('حداکثر تعداد سفارش‌های باز هر مشتری تا قبل از تسویه کامل آن‌ها.', 'deposits-partial-payments-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('ذخیره تغییرات تنظیمات', 'deposits-partial-payments-for-woocommerce'); ?></button>
                    </p>
                </form>
            </div>

            <!-- Test SMS Card -->
            <div class="vamariz-panel" style="flex: 1;">
                <div class="vamariz-panel-header">
                    <h2><?php esc_html_e('تست اتصال پیامکی', 'deposits-partial-payments-for-woocommerce'); ?></h2>
                    <p><?php esc_html_e('ارسال یک پیامک آزمایشی فوری به شماره دلخواه جهت بررسی اتصال سالم به ملی‌پیامک.', 'deposits-partial-payments-for-woocommerce'); ?></p>
                </div>
                <form method="post" action="">
                    <?php wp_nonce_field('vamariz_test_sms_action', 'vamariz_test_sms_nonce'); ?>
                    <p>
                        <label><strong><?php esc_html_e('شماره موبایل تست:', 'deposits-partial-payments-for-woocommerce'); ?></strong></label><br>
                        <input type="text" name="test_phone" placeholder="09123456789" required class="regular-text" style="width: 100%; margin-top: 5px;">
                    </p>
                    <p>
                        <button type="submit" class="button button-secondary"><?php esc_html_e('ارسال پیامک تستی ملی‌پیامک', 'deposits-partial-payments-for-woocommerce'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}
