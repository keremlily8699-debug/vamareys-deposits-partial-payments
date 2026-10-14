<?php
/**
 * Vamariz SnappPay Conditional Suppression & Product Widget + Modal
 *
 * @package VamarizInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_SnappPay {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Suppress SnappPay gateway at checkout for installment customers
        add_filter('woocommerce_available_payment_gateways', array($this, 'filter_gateways_for_installment_customer'), 50);

        // Single product page handling
        add_action('wp', array($this, 'setup_product_page_hooks'));
        add_action('wp_footer', array($this, 'render_installment_modal'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }

    /**
     * Disable SnappPay gateway at checkout if the user is an installment customer.
     */
    public function filter_gateways_for_installment_customer($gateways) {
        if (!is_admin() && Vamariz_Roles::is_installment_customer()) {
            if (is_array($gateways)) {
                foreach ($gateways as $id => $gateway) {
                    if (stripos($id, 'snapppay') !== false || (is_object($gateway) && stripos(get_class($gateway), 'snapppay') !== false)) {
                        unset($gateways[$id]);
                    }
                }
            }
        }
        return $gateways;
    }

    /**
     * Setup product page hooks: suppress SnappPay widget and inject Vamariz widget.
     */
    public function setup_product_page_hooks() {
        if (!is_product()) {
            return;
        }

        if (Vamariz_Roles::is_installment_customer()) {
            // Priority 25 places it right below price / before add-to-cart in Woodmart and standard WC
            add_action('woocommerce_single_product_summary', array($this, 'render_vamariz_pdp_widget'), 25);
            add_action('wp_head', array($this, 'suppress_snapppay_pdp_widget_css'), 999);
        }
    }

    /**
     * CSS snippet to suppress SnappPay PDP widget for installment customer.
     */
    public function suppress_snapppay_pdp_widget_css() {
        echo '<style id="vamariz-hide-snapppay">
            .snapppay-pdp-widget,
            .snapppay-widget,
            #snapppay-widget,
            .snapppay-pdp-container,
            [class*="snapppay"] {
                display: none !important;
            }
        </style>';
    }

    /**
     * Enqueue styles and dynamic JS for the PDP widget and modal.
     */
    public function enqueue_frontend_assets() {
        if (!is_product() && !is_cart() && !is_checkout()) {
            return;
        }

        if (!Vamariz_Roles::is_installment_customer()) {
            return;
        }

        wp_enqueue_style(
            'vamariz-frontend-css',
            plugins_url('assets/css/vamariz-frontend.css', dirname(dirname(__FILE__))),
            array(),
            AWCDP_VERSION
        );

        wp_enqueue_script(
            'vamariz-product-js',
            plugins_url('assets/js/vamariz-product.js', dirname(dirname(__FILE__))),
            array('jquery'),
            AWCDP_VERSION,
            true
        );

        global $product;
        $price = 0;
        if (is_product() && is_a($product, 'WC_Product')) {
            $price = function_exists('wc_get_price_to_display') ? wc_get_price_to_display($product) : (method_exists($product, 'get_price') ? $product->get_price() : 0);
        }

        wp_localize_script('vamariz-product-js', 'vamarizData', array(
            'basePrice'    => $price,
            'currency'     => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : 'تومان',
            'currencyText' => 'تومان',
        ));
    }

    /**
     * Render Vamariz 4-installment PDP Widget.
     */
    public function render_vamariz_pdp_widget() {
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }

        $price = function_exists('wc_get_price_to_display') ? wc_get_price_to_display($product) : (method_exists($product, 'get_price') ? $product->get_price() : 0);
        $installment_amount = round($price / 4);
        ?>
        <div class="vamariz-installment-widget" id="vamariz-installment-widget">
            <div class="vamariz-widget-badge">
                <span class="vamariz-badge-dot"></span>
                <span><?php esc_html_e('خرید اقساطی اختصاصی واماریز', 'deposits-partial-payments-for-woocommerce'); ?></span>
            </div>
            <div class="vamariz-widget-content">
                <div class="vamariz-widget-text">
                    <span class="vamariz-widget-title"><?php esc_html_e('در ۴ قسط ماهانه:', 'deposits-partial-payments-for-woocommerce'); ?></span>
                    <span class="vamariz-installment-amount" data-raw="<?php echo esc_attr($installment_amount); ?>">
                        <strong class="vamariz-calc-installment"><?php echo esc_html(number_format($installment_amount)); ?></strong>
                        <span class="vamariz-currency"><?php esc_html_e('تومان / ماه', 'deposits-partial-payments-for-woocommerce'); ?></span>
                    </span>
                </div>
                <button type="button" class="vamariz-modal-trigger" id="vamariz-open-modal-btn">
                    <span><?php esc_html_e('شرایط و مراحل اقساط', 'deposits-partial-payments-for-woocommerce'); ?></span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </button>
            </div>
            <div class="vamariz-widget-sub">
                <?php esc_html_e('۲۵٪ در لحظه خرید + ارسال فوری کالا + ۳ قسط ماهانه بدون سود و ضامن', 'deposits-partial-payments-for-woocommerce'); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Responsive Modal Dialog at wp_footer.
     */
    public function render_installment_modal() {
        if (!is_product() || !Vamariz_Roles::is_installment_customer()) {
            return;
        }

        global $product;
        $price = 0;
        if (is_a($product, 'WC_Product')) {
            $price = function_exists('wc_get_price_to_display') ? wc_get_price_to_display($product) : (method_exists($product, 'get_price') ? $product->get_price() : 0);
        }
        $installment = round($price / 4);
        ?>
        <div class="vamariz-modal-overlay" id="vamariz-modal-overlay" style="display:none;">
            <div class="vamariz-modal-dialog">
                <div class="vamariz-modal-header">
                    <div class="vamariz-header-title">
                        <span class="vamariz-logo-icon">V</span>
                        <h3><?php esc_html_e('خرید اقساطی ۴ ماهه واماریز', 'deposits-partial-payments-for-woocommerce'); ?></h3>
                    </div>
                    <button type="button" class="vamariz-modal-close" id="vamariz-modal-close-btn">&times;</button>
                </div>

                <div class="vamariz-modal-body">
                    <!-- Dynamic Installment Breakdown Table -->
                    <div class="vamariz-breakdown-card">
                        <div class="vamariz-card-header">
                            <span><?php esc_html_e('محاسبه لحظه‌ای اقساط این محصول', 'deposits-partial-payments-for-woocommerce'); ?></span>
                            <span class="vamariz-total-pill">
                                <?php esc_html_e('قیمت کالا:', 'deposits-partial-payments-for-woocommerce'); ?>
                                <strong class="vamariz-modal-total-display"><?php echo esc_html(number_format($price)); ?></strong> <?php esc_html_e('تومان', 'deposits-partial-payments-for-woocommerce'); ?>
                            </span>
                        </div>
                        <div class="vamariz-steps-grid">
                            <div class="vamariz-step-item active-step">
                                <span class="vamariz-step-num">قسط اول</span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-1"><?php echo esc_html(number_format($installment)); ?></strong> تومان</span>
                                <span class="vamariz-step-date">امروز (هنگام خرید)</span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num">قسط دوم</span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-2"><?php echo esc_html(number_format($installment)); ?></strong> تومان</span>
                                <span class="vamariz-step-date">۳۰ روز بعد</span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num">قسط سوم</span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-3"><?php echo esc_html(number_format($installment)); ?></strong> تومان</span>
                                <span class="vamariz-step-date">۶۰ روز بعد</span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num">قسط چهارم</span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-4"><?php echo esc_html(number_format($installment)); ?></strong> تومان</span>
                                <span class="vamariz-step-date">۹۰ روز بعد</span>
                            </div>
                        </div>
                    </div>

                    <!-- Process Steps -->
                    <div class="vamariz-info-section">
                        <h4><?php esc_html_e('مراحل دریافت کالا و پرداخت اقساط', 'deposits-partial-payments-for-woocommerce'); ?></h4>
                        <ul class="vamariz-info-steps">
                            <li>
                                <div class="vamariz-circle-num">۱</div>
                                <div>
                                    <strong><?php esc_html_e('ثبت سفارش و پرداخت قسط اول', 'deposits-partial-payments-for-woocommerce'); ?></strong>
                                    <p><?php esc_html_e('تنها با پرداخت ۲۵٪ از مبلغ کل کالا در صفحه تسویه‌حساب، سفارش شما ثبت می‌شود.', 'deposits-partial-payments-for-woocommerce'); ?></p>
                                </div>
                            </li>
                            <li>
                                <div class="vamariz-circle-num">۲</div>
                                <div>
                                    <strong><?php esc_html_e('ارسال فوری کالا توسط واماریز', 'deposits-partial-payments-for-woocommerce'); ?></strong>
                                    <p><?php esc_html_e('سفارش شما بلافاصله پس از پرداخت قسط اول بسته‌بندی و تحویل پست / پیک می‌گردد.', 'deposits-partial-payments-for-woocommerce'); ?></p>
                                </div>
                            </li>
                            <li>
                                <div class="vamariz-circle-num">۳</div>
                                <div>
                                    <strong><?php esc_html_e('یادآوری پیامکی و پرداخت آنلاین آسان', 'deposits-partial-payments-for-woocommerce'); ?></strong>
                                    <p><?php esc_html_e('پیش از موعد هر قسط، پیامک یادآوری حاوی لینک مستقیم پرداخت بانکی برای شما ارسال خواهد شد.', 'deposits-partial-payments-for-woocommerce'); ?></p>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- Terms & Conditions -->
                    <div class="vamariz-terms-box">
                        <strong><?php esc_html_e('شرایط و قوانین خوش‌حسابی:', 'deposits-partial-payments-for-woocommerce'); ?></strong>
                        <p><?php esc_html_e('این طرح ویژه مشتریان منتخب و وفادار واماریز بوده و بدون نیاز به چک، سفته یا ضامن ارائه می‌شود. در صورت تاخیر در تسویه هر قسط، امتیاز خرید اقساطی بعدی به صورت موقت معلق خواهد شد تا اقساط معوقه تسویه گردند.', 'deposits-partial-payments-for-woocommerce'); ?></p>
                    </div>
                </div>

                <div class="vamariz-modal-footer">
                    <button type="button" class="vamariz-btn-primary" id="vamariz-close-modal-confirm">
                        <?php esc_html_e('متوجه شدم، بازگشت به خرید', 'deposits-partial-payments-for-woocommerce'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}
