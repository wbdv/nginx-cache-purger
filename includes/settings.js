/* global jQuery, ngxcp_settings */
jQuery(function ($) {
    'use strict';

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
                $out.html('<span style="color:#00a32a;">' + r.data.message + '</span>');
            } else {
                $out.html('<span style="color:#d63638;">' + ((r && r.data && r.data.message) || 'Error') + '</span>');
            }
        }).fail(function () {
            $out.html('<span style="color:#d63638;">Request failed.</span>');
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
                $out.html('<span style="color:#00a32a;">' + r.data.message + '</span>');
                $btn.remove();
            } else {
                $out.html('<span style="color:#d63638;">' + ((r && r.data && r.data.message) || 'Error') + '</span>');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            $out.html('<span style="color:#d63638;">Request failed.</span>');
            $btn.prop('disabled', false);
        });
    });
});
