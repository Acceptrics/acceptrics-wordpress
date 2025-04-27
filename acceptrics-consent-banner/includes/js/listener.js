document.addEventListener('__acceptrics_consent_updated', function () {
    // Acceptrics is strictly an opt-in solution.
    window.wp_consent_type = 'optin';
    acceptricsSettings = JSON.parse(localStorage.getItem('__acceptrics_settings'));
    let event = new CustomEvent('wp_consent_type_defined');
    document.dispatchEvent(event);
    try {
        if (acceptricsSettings !== undefined) {
            if (acceptricsSettings.purposes?.analytics === 'accepted') {
                wp_set_consent('statistics', 'allow');
            } else {
                wp_set_consent('statistics', 'deny');
            }
            if (acceptricsSettings.purposes?.ads === 'accepted') {
                wp_set_consent('marketing', 'allow');
            } else {
                wp_set_consent('marketing', 'deny');
            }
        }
    } catch (e) {
        console.error("wp_set_consent is not defined", e);
    }
});