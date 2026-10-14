/**
 * Vamariz Admin Dashboard JS
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        $('.vamariz-copy-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var url = $btn.data('url');

            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function() {
                    var originalText = $btn.text();
                    $btn.text('کپی شد!').addClass('button-primary');
                    setTimeout(function() {
                        $btn.text(originalText).removeClass('button-primary');
                    }, 2000);
                });
            } else {
                // Fallback
                var $temp = $("<input>");
                $("body").append($temp);
                $temp.val(url).select();
                document.execCommand("copy");
                $temp.remove();

                var originalText = $btn.text();
                $btn.text('کپی شد!').addClass('button-primary');
                setTimeout(function() {
                    $btn.text(originalText).removeClass('button-primary');
                }, 2000);
            }
        });
    });

})(jQuery);
