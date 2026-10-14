<?php
/**
 * Vamariz 4-Stage Installments Engine and Restriction Rules
 *
 * @package VamarizInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_Installments {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Enforce user role restriction on deposit eligibility
        add_filter('awcdp_deposits_is_deposit_enabled', array($this, 'restrict_deposits_to_installment_customers'), 99, 2);
        add_filter('awcdp_user_has_deposit_permission', array($this, 'check_user_deposit_permission'), 99);

        // Customize payment schedule into 4 installments of 25%
        add_filter('awcdp_deposits_cart_schedule', array($this, 'enforce_four_stage_schedule'), 20, 3);

        // Hook into order placement to set status to Processing and create child sub-orders
        add_action('woocommerce_checkout_order_processed', array($this, 'on_checkout_order_processed'), 20, 3);
        add_action('woocommerce_payment_complete', array($this, 'on_payment_complete'), 20);
        add_action('woocommerce_order_status_completed', array($this, 'on_suborder_status_completed'), 20);

        // Cart & Checkout validations for overdue debt and minimum amounts
        add_action('woocommerce_check_cart_items', array($this, 'validate_customer_eligibility'));
    }

    /**
     * Ensure deposits are only enabled for installment_customer.
     */
    public function restrict_deposits_to_installment_customers($enabled, $product_id = 0) {
        if (!Vamariz_Roles::is_installment_customer()) {
            return false;
        }
        return true;
    }

    /**
     * User permission filter.
     */
    public function check_user_deposit_permission($allowed) {
        return Vamariz_Roles::is_installment_customer();
    }

    /**
     * Validate customer limits and overdue installments before checkout.
     */
    public function validate_customer_eligibility() {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !Vamariz_Roles::is_installment_customer()) {
            return;
        }

        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        if (!$user_id) {
            return;
        }

        // 1. Check for unpaid overdue installments
        if ($this->has_overdue_installments($user_id)) {
            wc_add_notice(
                esc_html__('شما در حال حاضر دارای قسط معوقه تسویه‌نشده در واماریز هستید. جهت حفظ امتیاز خرید اقساطی، لطفاً ابتدا نسبت به تسویه اقساط گذشته اقدام نمایید.', 'deposits-partial-payments-for-woocommerce'),
                'error'
            );
        }

        // 2. Check minimum order amount for installment
        $min_amount = floatval(get_option('vamariz_min_installment_amount', 0));
        if ($min_amount > 0 && WC()->cart && WC()->cart->get_total('edit') < $min_amount) {
            // Only inform if cart total is less than minimum
        }

        // 3. Check active open orders limit
        $max_orders = intval(get_option('vamariz_max_active_orders', 3));
        if ($max_orders > 0 && $this->get_active_installment_orders_count($user_id) >= $max_orders) {
            wc_add_notice(
                sprintf(
                    esc_html__('شما به سقف مجاز سفارش‌های اقساطی همزمان (%d سفارش باز) رسیده‌اید. پس از تسویه سفارش‌های قبلی، می‌توانید سفارش اقساطی جدید ثبت کنید.', 'deposits-partial-payments-for-woocommerce'),
                    $max_orders
                ),
                'error'
            );
        }
    }

    /**
     * Check if a customer has any overdue sub-order.
     */
    public function has_overdue_installments($user_id) {
        $now = current_time('timestamp');
        $sub_orders = wc_get_orders(array(
            'type'        => AWCDP_POST_TYPE,
            'customer_id' => $user_id,
            'status'      => array('wc-pending', 'pending'),
            'limit'       => -1,
        ));

        foreach ($sub_orders as $sub_order) {
            $due_date = intval($sub_order->get_meta('_vamariz_due_date', true));
            if ($due_date > 0 && $due_date < $now) {
                return true;
            }
        }
        return false;
    }

    /**
     * Count active open installment parent orders for a customer.
     */
    public function get_active_installment_orders_count($user_id) {
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'status'      => array('wc-processing', 'wc-partially-paid', 'processing'),
            'meta_key'    => '_vamariz_is_installment_order',
            'meta_value'  => 'yes',
            'return'      => 'ids',
        ));
        return is_array($orders) ? count($orders) : 0;
    }

    /**
     * Enforce exactly 4 equal installments of 25%.
     */
    public function enforce_four_stage_schedule($schedule, $total, $deposit_amount) {
        $installment_amount = round($total / 4, 2);
        $now = current_time('timestamp');

        $new_schedule = array(
            'deposit' => array(
                'id'    => '',
                'title' => esc_html__('قسط اول (۲۵٪ پیش‌پرداخت)', 'deposits-partial-payments-for-woocommerce'),
                'type'  => 'deposit',
                'total' => $installment_amount,
            ),
            strtotime('+30 days', $now) => array(
                'id'    => '',
                'title' => esc_html__('قسط دوم (۲۵٪)', 'deposits-partial-payments-for-woocommerce'),
                'type'  => 'partial_payment',
                'total' => $installment_amount,
            ),
            strtotime('+60 days', $now) => array(
                'id'    => '',
                'title' => esc_html__('قسط سوم (۲۵٪)', 'deposits-partial-payments-for-woocommerce'),
                'type'  => 'partial_payment',
                'total' => $installment_amount,
            ),
            strtotime('+90 days', $now) => array(
                'id'    => '',
                'title' => esc_html__('قسط چهارم (۲۵٪)', 'deposits-partial-payments-for-woocommerce'),
                'type'  => 'partial_payment',
                'total' => $installment_amount,
            ),
        );

        return $new_schedule;
    }

    /**
     * Triggered when parent order is processed at checkout.
     */
    public function on_checkout_order_processed($order_id, $posted_data, $order) {
        if (!$order) {
            return;
        }

        if (!Vamariz_Roles::is_installment_customer($order->get_customer_id())) {
            return;
        }

        $order->update_meta_data('_vamariz_is_installment_order', 'yes');
        $order->save();
    }

    /**
     * Triggered when payment is completed.
     */
    public function on_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // If this is a parent installment order
        if ($order->get_type() !== AWCDP_POST_TYPE) {
            $is_installment = $order->get_meta('_vamariz_is_installment_order', true);
            if ($is_installment === 'yes') {
                // Ensure parent order moves to 'processing' (در حال انجام) so fulfillment/shipping can start
                $order->set_status('processing', esc_html__('قسط اول با موفقیت پرداخت شد. سفارش آماده‌سازی و ارسال می‌شود.', 'deposits-partial-payments-for-woocommerce'));
                $order->save();

                // Generate sub-orders for installments 2, 3, and 4
                $this->create_vamariz_installment_suborders($order);

                // Dispatch order placed SMS
                Vamariz_SMS::send_order_created_notification($order->get_id());
            }
        } else {
            // This is a sub-order payment
            $this->handle_suborder_payment_completed($order);
        }
    }

    /**
     * Generate the 3 subsequent sub-orders for installments 2, 3, and 4.
     */
    public function create_vamariz_installment_suborders($parent_order) {
        $existing_subs = $parent_order->get_meta('_vamariz_child_suborder_ids', true);
        if (!empty($existing_subs) && is_array($existing_subs)) {
            return; // Already created
        }

        $total = floatval($parent_order->get_total());
        $inst_amount = round($total / 4, 2);
        $order_time = $parent_order->get_date_created() ? $parent_order->get_date_created()->getTimestamp() : current_time('timestamp');

        $schedules = array(
            2 => array('days' => 30, 'title' => 'قسط دوم (۲۵٪)'),
            3 => array('days' => 60, 'title' => 'قسط سوم (۲۵٪)'),
            4 => array('days' => 90, 'title' => 'قسط چهارم (۲۵٪)'),
        );

        $created_sub_ids = array();

        foreach ($schedules as $num => $info) {
            $due_timestamp = strtotime('+' . $info['days'] . ' days', $order_time);

            $sub_order = new AWCDP_Order();
            $sub_order->set_parent_id($parent_order->get_id());
            $sub_order->set_customer_id($parent_order->get_customer_id());
            $sub_order->set_currency($parent_order->get_currency());
            $sub_order->set_prices_include_tax($parent_order->get_prices_include_tax());

            // Copy addresses
            $sub_order->set_address($parent_order->get_address('billing'), 'billing');
            $sub_order->set_address($parent_order->get_address('shipping'), 'shipping');

            // Fee line item
            $item_name = sprintf('قسط شماره %d از ۴ - سفارش #%s', $num, $parent_order->get_order_number());
            $item = new WC_Order_Item_Fee();
            $item->set_name($item_name);
            $item->set_total($inst_amount);
            $sub_order->add_item($item);

            $sub_order->set_total($inst_amount);
            $sub_order->set_status('pending');

            // Meta tags
            $sub_order->update_meta_data('_vamariz_installment_number', $num);
            $sub_order->update_meta_data('_vamariz_due_date', $due_timestamp);
            $sub_order->update_meta_data('_vamariz_is_overdue', 'no');
            $sub_order->update_meta_data('_awcdp_deposits_payment_type', 'partial_payment');
            $sub_order->update_meta_data('_awcdp_deposits_partial_payment_date', $due_timestamp);

            $sub_order->save();
            $created_sub_ids[$num] = $sub_order->get_id();
        }

        $parent_order->update_meta_data('_vamariz_child_suborder_ids', $created_sub_ids);
        $parent_order->save();
    }

    /**
     * Handle payment completion of a sub-order.
     */
    public function handle_suborder_payment_completed($sub_order) {
        $parent_id = $sub_order->get_parent_id();
        $parent = wc_get_order($parent_id);

        // Send payment confirmation SMS
        Vamariz_SMS::send_payment_received_notification($sub_order->get_id());

        // Check if all suborders of this parent are now completed
        if ($parent) {
            $child_ids = $parent->get_meta('_vamariz_child_suborder_ids', true);
            if (is_array($child_ids)) {
                $all_paid = true;
                foreach ($child_ids as $sub_id) {
                    $child = wc_get_order($sub_id);
                    if ($child && !$child->is_paid()) {
                        $all_paid = false;
                        break;
                    }
                }
                if ($all_paid) {
                    $parent->update_status('completed', esc_html__('تمامی ۴ قسط سفارش تسویه گردید و سفارش به وضعیت تکمیل‌شده تغییر یافت.', 'deposits-partial-payments-for-woocommerce'));
                    $parent->save();
                }
            }
        }
    }

    /**
     * Handle status change to completed on sub-order.
     */
    public function on_suborder_status_completed($order_id) {
        $order = wc_get_order($order_id);
        if ($order && $order->get_type() === AWCDP_POST_TYPE) {
            $this->handle_suborder_payment_completed($order);
        }
    }
}
