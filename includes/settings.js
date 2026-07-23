/* global jQuery, ncp_settings */
jQuery(function ($) {
    'use strict';

    $('#ncp-cache-test').on('click', function () {
        var $btn = $(this),
            $out = $('#ncp-cache-test-result');
        $btn.prop('disabled', true);
        $out.text(ncp_settings.testing);

        $.post(ncp_settings.ajax_url, {
            action: 'ncp_cache_test',
            nonce: ncp_settings.test_nonce
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

    $('#ncp-cron-setup').on('click', function () {
        var $btn = $(this),
            $out = $('#ncp-cron-setup-result');
        $btn.prop('disabled', true);
        $out.text(ncp_settings.working);

        $.post(ncp_settings.ajax_url, {
            action: 'ncp_cron_setup',
            nonce: ncp_settings.cron_nonce
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
});
