/**
 * Vamariz Product Page Widget & Dynamic Modal Handler
 */

(function($) {
    'use strict';

    function formatNumber(num) {
        return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function updateInstallmentDisplay(totalPrice) {
        if (!totalPrice || totalPrice <= 0) {
            return;
        }

        var installment = Math.round(totalPrice / 4);
        var formattedInstallment = formatNumber(installment);
        var formattedTotal = formatNumber(totalPrice);

        // Update PDP widget
        $('.vamariz-calc-installment').text(formattedInstallment);
        $('.vamariz-installment-amount').attr('data-raw', installment);

        // Update Modal elements
        $('.vamariz-modal-total-display').text(formattedTotal);
        $('.vamariz-modal-inst-1').text(formattedInstallment);
        $('.vamariz-modal-inst-2').text(formattedInstallment);
        $('.vamariz-modal-inst-3').text(formattedInstallment);
        $('.vamariz-modal-inst-4').text(formattedInstallment);
    }

    $(document).ready(function() {
        // Modal Open / Close
        $(document).on('click', '#vamariz-open-modal-btn', function(e) {
            e.preventDefault();
            $('#vamariz-modal-overlay').fadeIn(180);
            $('body').addClass('vamariz-modal-open');
        });

        $(document).on('click', '#vamariz-modal-close-btn, #vamariz-close-modal-confirm', function(e) {
            e.preventDefault();
            $('#vamariz-modal-overlay').fadeOut(140);
            $('body').removeClass('vamariz-modal-open');
        });

        // Close on clicking backdrop
        $(document).on('click', '#vamariz-modal-overlay', function(e) {
            if ($(e.target).is('#vamariz-modal-overlay')) {
                $('#vamariz-modal-overlay').fadeOut(140);
                $('body').removeClass('vamariz-modal-open');
            }
        });

        // Close on ESC
        $(document).keyup(function(e) {
            if (e.key === "Escape") {
                $('#vamariz-modal-overlay').fadeOut(140);
                $('body').removeClass('vamariz-modal-open');
            }
        });

        // Listen to Variable Product Variation Changes
        $('.variations_form').on('found_variation', function(event, variation) {
            if (variation && variation.display_price) {
                updateInstallmentDisplay(variation.display_price);
            }
        });

        $('.variations_form').on('reset_data', function() {
            if (window.vamarizData && window.vamarizData.basePrice) {
                updateInstallmentDisplay(window.vamarizData.basePrice);
            }
        });
    });

})(jQuery);
