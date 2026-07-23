/* global jQuery, ncp_ajax_object */
jQuery(document).ready(function ($) {
    'use strict';

    var $purgeButton = $('#wp-admin-bar-ncp-purge-nginx-cache .ab-item');

    if (!$purgeButton.length) {
        return;
    }

    // Minimum time the spinner stays visible. A purge usually answers in a few
    // milliseconds, which would make the button flicker instead of showing
    // that something happened.
    var MIN_SPIN_MS = 500;

    /**
     * Render a WordPress-style notice.
     *
     * In wp-admin it is injected into the normal notice position so it looks
     * exactly like any core message. On the front end there is no notice area,
     * so it becomes a floating toast with the same colours.
     *
     * @param {string} type    'success' or 'error'.
     * @param {string} message Text to display.
     */
    function ncpNotice(type, message) {
        $('.ncp-notice').remove();

        var $notice = $('<div/>', {
            'class': 'ncp-notice notice notice-' + type + ' is-dismissible',
            'role': 'status',
            'aria-live': 'polite'
        }).append($('<p/>').text(message));

        var $dismiss = $('<button/>', {
            'type': 'button',
            'class': 'notice-dismiss'
        }).append($('<span/>', {
            'class': 'screen-reader-text',
            'text': ncp_ajax_object.dismiss_label
        }));

        $dismiss.on('click', function () {
            $notice.remove();
        });

        $notice.append($dismiss);

        /*
         * Place it exactly where core places notices, so it lines up with the
         * page content on every screen.
         *
         * Screens differ in what they offer: most modern ones print an empty
         * <hr class="wp-header-end"> right after the page title for precisely
         * this purpose, but plenty (the Dashboard among them) do not. Falling
         * back to #wpbody-content puts the notice *outside* .wrap, where core's
         * margins make it run full width and sit above the <h1> — which is why
         * alignment looked inconsistent from screen to screen.
         */
        var $wrap = $('#wpbody-content .wrap').first();

        if ($wrap.length) {
            var $anchor = $wrap.find('.wp-header-end').first();

            if (!$anchor.length) {
                // No marker: go straight after the page heading.
                $anchor = $wrap.children('h1, h2').first();
            }

            if ($anchor.length) {
                $anchor.after($notice);
            } else {
                $wrap.prepend($notice);
            }
        } else if ($('#wpbody-content').length) {
            // No .wrap on this screen — indent it ourselves (see the CSS).
            $notice.addClass('ncp-notice-standalone');
            $('#wpbody-content').prepend($notice);
        } else {
            // Front end — no notice area, so float it.
            $notice.addClass('ncp-toast');
            $('body').append($notice);
        }

        if ($notice.hasClass('ncp-toast')) {
            window.setTimeout(function () {
                $notice.fadeOut(200, function () {
                    $(this).remove();
                });
            }, 6000);
        } else if (window.scrollY > 0) {
            // Scroll the notice into view when the page is already scrolled down.
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    $purgeButton.on('click', function (e) {
        e.preventDefault();

        if ($purgeButton.hasClass('ncp-purging')) {
            return;
        }

        var originalButtonText = $purgeButton.html();
        var startedAt = Date.now();

        $purgeButton
            .html('<span class="ab-icon dashicons-before dashicons-update"></span> ' + ncp_ajax_object.purging_message)
            .addClass('ncp-purging');

        function restoreButton() {
            var elapsed = Date.now() - startedAt;
            var wait = Math.max(0, MIN_SPIN_MS - elapsed);

            window.setTimeout(function () {
                $purgeButton.html(originalButtonText).removeClass('ncp-purging');
            }, wait);
        }

        $.ajax({
            url: ncp_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'ncp_purge_nginx_cache', // The AJAX action defined in PHP
                nonce: ncp_ajax_object.nonce     // The security nonce
            }
        }).done(function (response) {
            if (response && response.success) {
                ncpNotice('success', (response.data && response.data.message) || ncp_ajax_object.success_message);
            } else {
                var detail = (response && response.data && response.data.message) ? response.data.message : '';
                ncpNotice('error', detail || ncp_ajax_object.error_message);
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            if (window.console) {
                window.console.error('Nginx Cache Purger AJAX error:', textStatus, errorThrown);
            }
            ncpNotice('error', ncp_ajax_object.error_message + ' (' + textStatus + ')');
        }).always(restoreButton);
    });
});
