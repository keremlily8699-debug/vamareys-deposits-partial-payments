<?php
/**
 * Order details Summary
 *
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!$order = wc_get_order($order_id)) {
    return;
}

?>

<p class="awcdp_deposits_summary_title"><?php esc_html_e('جدول وضعیت اقساط ۴ ماهه واماریز', 'deposits-partial-payments-for-woocommerce') ?></p>
<table class="woocommerce-table awcdp_deposits_summary" dir="rtl">
  <thead>
    <tr>
        <th><?php esc_html_e('شناسه قسط', 'deposits-partial-payments-for-woocommerce'); ?></th>
        <th><?php esc_html_e('عنوان / سررسید', 'deposits-partial-payments-for-woocommerce'); ?></th>
        <th><?php esc_html_e('مبلغ قسط', 'deposits-partial-payments-for-woocommerce'); ?></th>
        <th><?php esc_html_e('وضعیت و تسویه', 'deposits-partial-payments-for-woocommerce'); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($schedule as $timestamp => $payment){
        $title = '';
        if(isset($payment['title'])){
          $title  = $payment['title'];
        } else {
          if(!is_numeric($timestamp)){
            if($timestamp === 'unlimited'){
              $title = esc_html__('اقساط آتی', 'deposits-partial-payments-for-woocommerce');
            } elseif($timestamp === 'deposit'){
              $title = esc_html__('قسط اول (پیش‌پرداخت)', 'deposits-partial-payments-for-woocommerce');
            } else {
              $title = $timestamp;
            }
          } else {
            $title = date_i18n(wc_date_format(),$timestamp);
          }
      }
      $title = apply_filters('awcdp_partial_payment_title',$title,$payment);
      $payment_order = false;
      if (isset($payment['id']) && !empty($payment['id'])) {
          $payment_order = wc_get_order($payment['id']);
      }
      $payment_id = $payment_order ? $payment_order->get_order_number() : '-';
      $status = ($payment_order && function_exists('wc_get_order_status_name')) ? wc_get_order_status_name($payment_order->get_status()) : ($payment_order ? $payment_order->get_status() : esc_html__('در انتظار سررسید', 'deposits-partial-payments-for-woocommerce'));
      $amount = $payment_order ? $payment_order->get_total() : (isset($payment['total']) ? $payment['total'] : 0);
      $currency = $payment_order ? $payment_order->get_currency() : ($order ? $order->get_currency() : 'IRT');
      $price_args = array('currency' => $currency);
      ?>
      <tr>
          <td> <strong>#<?php echo wp_kses_post( $payment_id); ?></strong> </td>
          <td> <?php echo wp_kses_post( $title); ?> </td>
          <td> <strong><?php echo wp_kses_post( wc_price($amount,$price_args)); ?></strong> </td>
          <td>
              <span><?php echo wp_kses_post( $status); ?></span>
              <?php if ($payment_order && !$payment_order->is_paid() && (method_exists($payment_order, 'needs_payment') ? $payment_order->needs_payment() : true)): ?>
                  <a href="<?php echo esc_url($payment_order->get_checkout_payment_url()); ?>" class="button pay" style="margin-right: 8px; font-size: 11px; padding: 4px 10px; background: #0f172a; color: #fff;">
                      <?php esc_html_e('پرداخت آنلاین این قسط', 'deposits-partial-payments-for-woocommerce'); ?>
                  </a>
              <?php endif; ?>
          </td>
      </tr>
    <?php
    }
    ?>
    </tbody>
</table>


		  <?php 
		  /*
			$balance_text = esc_html__('Make balance payment :', 'deposits-partial-payments-for-woocommerce');
			$balance_text = apply_filters('awcdp_balance_payment_text',$balance_text);
		  
			$actions = wc_get_account_orders_actions( $order );
			if ( ! empty( $actions ) ) {
				foreach ( $actions as $key => $action ) {
					if( $key == 'pay' ){
						echo '<div class="awcdp_balance_pay ">';
						echo '<p>' . $balance_text . '<a href="' . esc_url( $action['url'] ) . '" class="button ' . sanitize_html_class( $key ) . '">' . esc_html( $action['name'] ) . '</a> </p>';
						echo '</div>';
					}
				}
			}
			*/
		  ?> 

