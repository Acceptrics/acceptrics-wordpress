/**
 * Acceptrics WP Consent API Bridge
 *
 * Listens for Acceptrics consent events and syncs the result
 * with the WP Consent API so other WordPress plugins respect
 * the user's consent choices.
 *
 * Acceptrics operates as a strict opt-in solution.
 */
(function () {
    'use strict';

    document.addEventListener('__acceptrics_consent_updated', function () {
        // Signal opt-in consent model to the WP Consent API
        window.wp_consent_type = 'optin';

        var acceptricsSettings = null;
        try {
            acceptricsSettings = JSON.parse(localStorage.getItem('__acceptrics_settings'));
        } catch (e) {
            // localStorage may be unavailable in certain browser contexts
        }

        // Notify WP Consent API that the consent type is defined
        document.dispatchEvent(new CustomEvent('wp_consent_type_defined'));

        try {
            if (acceptricsSettings && acceptricsSettings.purposes) {
                wp_set_consent(
                    'statistics',
                    acceptricsSettings.purposes.analytics === 'accepted' ? 'allow' : 'deny'
                );
                wp_set_consent(
                    'marketing',
                    acceptricsSettings.purposes.ads === 'accepted' ? 'allow' : 'deny'
                );
            }
        } catch (e) {
            // wp_set_consent is only available when the WP Consent API plugin is active
        }
    });
}());
