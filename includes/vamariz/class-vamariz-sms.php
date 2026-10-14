<?php
/**
 * Vamariz Melipayamak SMS Integration
 *
 * @package VamarizInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_SMS {

    const DEFAULT_API_URL = 'https://console.melipayamak.com/api/send/simple/1cf6abc67a3c471e9a61c617e82e394f';
    const DEFAULT_FROM = '50004001';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Daily Cron for reminders
        add_action('vamariz_daily_installment_cron', array($this, 'run_daily_reminders'));
        add_action('init', array($this, 'maybe_schedule_cron'));
    }

    public function maybe_schedule_cron() {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (!wp_next_scheduled('vamariz_daily_installment_cron')) {
            $time = strtotime('tomorrow 09:00:00');
            wp_schedule_event($time ? $time : (time() + 86400), 'daily', 'vamariz_daily_installment_cron');
        }
    }

    /**
     * Send SMS via Melipayamak REST Simple API.
     *
     * @param string $to Recipient phone number (e.g. 09123456789).
     * @param string $text SMS message content.
     * @return array Result array with success flag and status/recId.
     */
    public static function send_sms($to, $text) {
        $api_url = get_option('vamariz_sms_api_url', self::DEFAULT_API_URL);
        $from = get_option('vamariz_sms_from_number', self::DEFAULT_FROM);

        // Clean recipient number
        $to = self::normalize_phone($to);
        if (empty($to) || empty($text)) {
            return array('success' => false, 'message' => 'شماره گیرنده یا متن پیامک نامعتبر است.');
        }

        $payload = array(
            'from' => $from,
            'to'   => $to,
            'text' => $text,
        );

        if (!function_exists('wp_remote_post')) {
            return array('success' => false, 'message' => 'WordPress HTTP API not loaded.');
        }

        $response = wp_remote_post($api_url, array(
            'method'      => 'POST',
            'timeout'     => 15,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'        => wp_json_encode($payload),
            'sslverify'   => false,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['recId']) && !empty($data['recId'])) {
            return array('success' => true, 'recId' => $data['recId'], 'status' => isset($data['status']) ? $data['status'] : '');
        }

        $error_msg = isset($data['status']) ? $data['status'] : 'خطای نامشخص در ارسال پیامک';
        return array('success' => false, 'message' => $error_msg, 'response' => $body);
    }

    /**
     * Normalize Iranian phone numbers into 09xxxxxxxxx format.
     */
    public static function normalize_phone($phone) {
        $phone = trim((string) $phone);

        // Convert Persian/Arabic digits
        $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        $arabic  = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');
        $english = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $phone = str_replace($persian, $english, $phone);
        $phone = str_replace($arabic, $english, $phone);

        // Strip all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);

        // Strip country code prefix (0098 or 98)
        if (strpos($phone, '0098') === 0) {
            $phone = substr($phone, 4);
        } elseif (strpos($phone, '98') === 0 && strlen($phone) >= 12) {
            $phone = substr($phone, 2);
        }

        // Strip any leading zeros
        $phone = ltrim($phone, '0');

        // If it is a 10-digit Iranian mobile starting with 9, format as 09xxxxxxxxx
        if (strlen($phone) === 10 && substr($phone, 0, 1) === '9') {
            return '0' . $phone;
        }

        // Fallback: if already starts with 09 and is 11 digits
        if (strlen($phone) === 11 && substr($phone, 0, 2) === '09') {
            return $phone;
        }

        return !empty($phone) ? ('0' . $phone) : '';
    }

    /**
     * Notification 1: Initial Order Placed with Installment Plan.
     */
    public static function send_order_created_notification($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $phone = $order->get_billing_phone();
        $name = $order->get_billing_first_name() ? $order->get_billing_first_name() : 'مشتری';

        $text = sprintf(
            "%s عزیز، سفارش اقساطی شما به شماره #%s در واماریز با موفقیت ثبت شد و قسط اول وصول گردید.\nکالای شما به زودی آماده‌سازی و ارسال خواهد شد.\n۳ قسط بعدی به صورت ماهانه سررسید خواهند شد و یادآوری پرداخت برای شما ارسال می‌گردد.\nبا تشکر از خرید شما - واماریز",
            $name,
            $order->get_order_number()
        );

        return self::send_sms($phone, $text);
    }

    /**
     * Notification 2: Installment Reminder (3 days before or on due date).
     */
    public static function send_installment_reminder($sub_order_id, $days_left = 3) {
        $sub_order = wc_get_order($sub_order_id);
        if (!$sub_order) {
            return false;
        }

        $parent = wc_get_order($sub_order->get_parent_id());
        $phone = $parent ? $parent->get_billing_phone() : $sub_order->get_billing_phone();
        $name = $parent ? $parent->get_billing_first_name() : $sub_order->get_billing_first_name();
        $name = $name ? $name : 'مشتری';

        $order_num = $parent ? $parent->get_order_number() : $sub_order->get_order_number();
        $installment_num = $sub_order->get_meta('_vamariz_installment_number', true);
        $installment_num = $installment_num ? $installment_num : 'بعدی';
        $amount = number_format($sub_order->get_total());
        $pay_url = method_exists($sub_order, 'get_checkout_payment_url') ? $sub_order->get_checkout_payment_url() : home_url('/checkout/order-pay/' . $sub_order->get_id() . '/');

        if ($days_left > 0) {
            $days_text = ($days_left == 1) ? 'فردا' : ($days_left . ' روز آینده');
            $text = sprintf(
                "%s عزیز، یادآوری سررسید قسط شماره %s سفارش #%s در واماریز:\nمبلغ: %s تومان\nسررسید: %s\nجهت تسویه آنلاین و مستقیم روی لینک زیر کلیک کنید:\n%s\nواماریز",
                $name,
                $installment_num,
                $order_num,
                $amount,
                $days_text,
                $pay_url
            );
        } else {
            $text = sprintf(
                "%s عزیز، امروز موعد پرداخت قسط شماره %s سفارش #%s در واماریز می‌باشد.\nمبلغ: %s تومان\nجهت تسویه آنلاین سریع، روی لینک زیر کلیک فرمایید:\n%s\nواماریز",
                $name,
                $installment_num,
                $order_num,
                $amount,
                $pay_url
            );
        }

        $res = self::send_sms($phone, $text);
        if ($res['success']) {
            $sub_order->update_meta_data('_vamariz_last_reminder_date', current_time('Y-m-d'));
            $sub_order->save();
        }
        return $res;
    }

    /**
     * Notification 3: Payment Received for an Installment.
     */
    public static function send_payment_received_notification($sub_order_id) {
        $sub_order = wc_get_order($sub_order_id);
        if (!$sub_order) {
            return false;
        }

        $parent = wc_get_order($sub_order->get_parent_id());
        $phone = $parent ? $parent->get_billing_phone() : $sub_order->get_billing_phone();
        $name = $parent ? $parent->get_billing_first_name() : $sub_order->get_billing_first_name();
        $name = $name ? $name : 'مشتری';

        $order_num = $parent ? $parent->get_order_number() : $sub_order->get_order_number();
        $installment_num = $sub_order->get_meta('_vamariz_installment_number', true);
        $amount = number_format($sub_order->get_total());

        $text = sprintf(
            "%s عزیز، پرداخت قسط شماره %s سفارش #%s به مبلغ %s تومان با موفقیت وصول گردید.\nاز خوش‌حسابی و همراهی شما سپاسگزاریم.\nفروشگاه اینترنتی واماریز",
            $name,
            $installment_num ? $installment_num : '',
            $order_num,
            $amount
        );

        return self::send_sms($phone, $text);
    }

    /**
     * Notification 4: Overdue Warning (2 days past due date).
     */
    public static function send_overdue_notification($sub_order_id) {
        $sub_order = wc_get_order($sub_order_id);
        if (!$sub_order) {
            return false;
        }

        $parent = wc_get_order($sub_order->get_parent_id());
        $phone = $parent ? $parent->get_billing_phone() : $sub_order->get_billing_phone();
        $name = $parent ? $parent->get_billing_first_name() : $sub_order->get_billing_first_name();
        $name = $name ? $name : 'مشتری';

        $order_num = $parent ? $parent->get_order_number() : $sub_order->get_order_number();
        $installment_num = $sub_order->get_meta('_vamariz_installment_number', true);
        $amount = number_format($sub_order->get_total());
        $pay_url = $sub_order->get_checkout_payment_url();

        $text = sprintf(
            "هشدار تاخیر قسط - %s عزیز، سررسید قسط شماره %s سفارش #%s به مبلغ %s تومان سپری شده و هنوز تسویه نشده است.\nجهت جلوگیری از مسدود شدن امتیاز خرید اقساطی شما در واماریز، لطفاً سریعاً از لینک زیر تسویه فرمایید:\n%s\nواماریز",
            $name,
            $installment_num ? $installment_num : '',
            $order_num,
            $amount,
            $pay_url
        );

        $res = self::send_sms($phone, $text);
        if ($res['success']) {
            $sub_order->update_meta_data('_vamariz_last_overdue_sms_date', current_time('Y-m-d'));
            $sub_order->save();
        }
        return $res;
    }

    /**
     * Notification 5: Invitation Link SMS.
     */
    public static function send_invite_sms($phone, $name, $invite_url) {
        $text = sprintf(
            "%s عزیز، شما به باشگاه خرید اقساطی ۴ ماهه واماریز دعوت شدید.\nجهت فعال‌سازی این امتیاز و تکمیل ثبت‌نام، روی لینک زیر کلیک کنید:\n%s\nفروشگاه اینترنتی واماریز",
            $name,
            $invite_url
        );

        return self::send_sms($phone, $text);
    }

    /**
     * Daily Cron job to scan sub-orders and dispatch reminders.
     */
    public function run_daily_reminders() {
        $today = current_time('timestamp');
        $today_str = current_time('Y-m-d');

        // Query pending installment sub-orders (supports HPOS and legacy storage)
        $sub_orders = array();
        if (function_exists('wc_get_orders')) {
            $sub_orders = wc_get_orders(array(
                'type'        => AWCDP_POST_TYPE,
                'status'      => array('wc-pending', 'pending'),
                'limit'       => 100,
                'meta_query'  => array(
                    array(
                        'key'     => '_vamariz_due_date',
                        'compare' => 'EXISTS',
                    ),
                ),
            ));
        } else {
            $args = array(
                'post_type'      => AWCDP_POST_TYPE,
                'post_status'    => array('wc-pending', 'pending'),
                'posts_per_page' => 100,
                'meta_query'     => array(
                    array(
                        'key'     => '_vamariz_due_date',
                        'compare' => 'EXISTS',
                    ),
                ),
            );

            $query = new WP_Query($args);
            if ($query->have_posts()) {
                foreach ($query->posts as $post) {
                    $order = wc_get_order($post->ID);
                    if ($order) {
                        $sub_orders[] = $order;
                    }
                }
            }
        }

        if (empty($sub_orders)) {
            return;
        }

        foreach ($sub_orders as $sub_order) {
            if (!$sub_order || $sub_order->is_paid()) {
                continue;
            }

            $due_date = intval($sub_order->get_meta('_vamariz_due_date', true));
            if (!$due_date) {
                continue;
            }

            $before_sent = $sub_order->get_meta('_vamariz_reminder_before_sent', true);
            $due_sent    = $sub_order->get_meta('_vamariz_reminder_due_sent', true);
            $last_overdue_sent = $sub_order->get_meta('_vamariz_last_overdue_sms_date', true);

            // Difference in days
            $diff_seconds = $due_date - $today;
            $diff_days = round($diff_seconds / 86400);

            // 1. Upcoming reminder (1 to 3 days before due date, sent once)
            if ($diff_days <= 3 && $diff_days > 0 && empty($before_sent)) {
                $res = self::send_installment_reminder($sub_order->get_id(), $diff_days);
                if (!empty($res['success'])) {
                    $sub_order->update_meta_data('_vamariz_reminder_before_sent', $today_str);
                    $sub_order->save();
                }
            }
            // 2. On due day (today)
            elseif ($diff_days == 0 && $due_sent !== $today_str) {
                $res = self::send_installment_reminder($sub_order->get_id(), 0);
                if (!empty($res['success'])) {
                    $sub_order->update_meta_data('_vamariz_reminder_due_sent', $today_str);
                    $sub_order->save();
                }
            }
            // 3. Overdue by 2 or more days
            elseif ($diff_days <= -2 && $last_overdue_sent !== $today_str) {
                $sub_order->update_meta_data('_vamariz_is_overdue', 'yes');
                $sub_order->save();
                self::send_overdue_notification($sub_order->get_id());
            }
        }
    }
}
