/* global jQuery, ngxcp_settings */
jQuery(function ($) {
    'use strict';

    /**
     * Show a coloured status message. Uses .text() so anything the server
     * relayed (e.g. a cache-status response header echoed back in the verdict)
     * is inserted as text, never parsed as HTML.
     *
     * @param {jQuery} $out  Target element.
     * @param {string} color CSS colour.
     * @param {string} text  Message.
     */
    function ngxcpStatus($out, color, text) {
        $out.empty().append($('<span/>').css('color', color).text(text));
    }

    $('#ngxcp-cache-test').on('click', function () {
        var $btn = $(this),
            $out = $('#ngxcp-cache-test-result');
        $btn.prop('disabled', true);
        $out.text(ngxcp_settings.testing);

        $.post(ngxcp_settings.ajax_url, {
            action: 'ngxcp_cache_test',
            nonce: ngxcp_settings.test_nonce
        }).done(function (r) {
            if (r && r.success) {
                ngxcpStatus($out, '#00a32a', r.data.message);
            } else {
                ngxcpStatus($out, '#d63638', (r && r.data && r.data.message) || 'Error');
            }
        }).fail(function () {
            ngxcpStatus($out, '#d63638', 'Request failed.');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $('#ngxcp-cron-setup').on('click', function () {
        var $btn = $(this),
            $out = $('#ngxcp-cron-setup-result');
        $btn.prop('disabled', true);
        $out.text(ngxcp_settings.working);

        $.post(ngxcp_settings.ajax_url, {
            action: 'ngxcp_cron_setup',
            nonce: ngxcp_settings.cron_nonce
        }).done(function (r) {
            if (r && r.success) {
                // Update the UI directly rather than waiting for a refresh: the
                // running PHP process booted before the define existed, so the
                // button's server-side condition would still show it for a moment.
                ngxcpStatus($out, '#00a32a', r.data.message);
                $btn.remove();
            } else {
                ngxcpStatus($out, '#d63638', (r && r.data && r.data.message) || 'Error');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            ngxcpStatus($out, '#d63638', 'Request failed.');
            $btn.prop('disabled', false);
        });
    });
});
