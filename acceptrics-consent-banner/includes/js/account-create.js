/**
 * Embedded account creation on the plugin settings page.
 * For customers who installed the plugin without an Acceptrics account:
 * collects an email + region, creates the account via admin-ajax (the PHP
 * side calls the Acceptrics Lambda), saves the code, enables the banner.
 */
(function ($) {
    'use strict';

    $(function () {
        var $btn = $('#acpt-create-btn');
        if (!$btn.length || typeof acceptricsCreateData === 'undefined') {
            return;
        }

        var $status = $('#acpt-create-status');
        var btnLabel = $btn.text();

        function showError(html) {
            $status.removeClass('acpt-status-ok').addClass('acpt-status-err').html(html);
            $btn.prop('disabled', false).text(btnLabel);
        }

        $btn.on('click', function () {
            var email = $.trim($('#acpt-create-email').val() || '');
            var geo = $('input[name="acpt_create_geo"]:checked').val() || 'eea';

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showError('Please enter a valid email address.');
                return;
            }

            $btn.prop('disabled', true).text('Creating your account…');
            $status.removeClass('acpt-status-err acpt-status-ok').text('');

            $.post(acceptricsCreateData.ajaxUrl, {
                action: 'acceptrics_create_account',
                nonce: acceptricsCreateData.nonce,
                email: email,
                geo_area: geo
            }).done(function (res) {
                if (res && res.success && res.data && res.data.accountNum) {
                    var msg = 'Account created! Your banner code <code>' + res.data.accountNum +
                        '</code> has been saved and the banner is enabled. ';
                    if (geo === 'eea') {
                        msg += 'The banner shows to EU/EEA visitors — if you are browsing from outside the EU, you may not see it on your own site. ';
                    } else {
                        msg += 'Open your site in a private/incognito window to see it live. ';
                    }
                    msg += 'A copy of your code is in your welcome email. Reloading…';
                    $status.removeClass('acpt-status-err').addClass('acpt-status-ok').html(msg);
                    setTimeout(function () { window.location.reload(); }, 3500);
                } else {
                    showError((res && res.data && res.data.message) || 'Something went wrong. Please try again.');
                }
            }).fail(function () {
                showError('Could not reach the server. Please try again.');
            });
        });
    });
})(jQuery);

/**
 * Telemetry opt-in prompt + Integrations toggle.
 * Sends the admin's choice; on "Allow" the server backfills the activation event.
 */
(function ($) {
    'use strict';

    $(function () {
        if (typeof acceptricsCreateData === 'undefined' || !acceptricsCreateData.telemetryNonce) {
            return;
        }

        function sendChoice(choice, done) {
            $.post(acceptricsCreateData.ajaxUrl, {
                action: 'acceptrics_telemetry_choice',
                nonce: acceptricsCreateData.telemetryNonce,
                choice: choice
            }).done(function (res) {
                done(!!(res && res.success));
            }).fail(function () { done(false); });
        }

        var $prompt = $('#acpt-telemetry-prompt');
        $('#acpt-tel-allow, #acpt-tel-deny').on('click', function () {
            var choice = this.id === 'acpt-tel-allow' ? 'granted' : 'denied';
            var $btns = $('#acpt-tel-allow, #acpt-tel-deny').prop('disabled', true);
            sendChoice(choice, function (ok) {
                if (ok) {
                    $prompt.html('<p class="acpt-card-desc" style="margin:0;">' +
                        (choice === 'granted'
                            ? 'Thanks! Usage data sharing is on. Change it anytime under Integrations.'
                            : 'No problem — nothing will be sent. Change your mind anytime under Integrations.') +
                        '</p>');
                    setTimeout(function () { $prompt.slideUp(300); }, 3500);
                } else {
                    $btns.prop('disabled', false);
                }
            });
        });

        $('#acpt-tel-toggle').on('click', function (e) {
            e.preventDefault();
            var $link = $(this);
            sendChoice($link.data('next'), function (ok) {
                if (ok) { window.location.reload(); }
            });
        });
    });
})(jQuery);
