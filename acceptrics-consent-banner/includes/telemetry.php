<?php
/**
 * Opt-in usage telemetry (PostHog).
 *
 * Sends product-usage events for the ADMIN's plugin journey only — never
 * anything about site visitors. Disabled until the site admin explicitly
 * opts in on the settings page (wp.org guideline 7: no phoning home without
 * informed consent). The choice is stored in `acceptrics_telemetry`:
 * '' = not asked yet, 'granted', or 'denied'.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ACCEPTRICS_POSTHOG_KEY')) {
    define('ACCEPTRICS_POSTHOG_KEY', 'phc_nAHpRjIZdQmr4u8myPiEhL7ehw48juxwTbV2lfSRQPw');
}
if (!defined('ACCEPTRICS_POSTHOG_HOST')) {
    define('ACCEPTRICS_POSTHOG_HOST', 'https://us.i.posthog.com');
}

function acceptrics_telemetry_state() {
    return get_option('acceptrics_telemetry', '');
}

/**
 * Fire-and-forget event capture. No-op unless the admin opted in.
 */
function acceptrics_track($event, $props = []) {
    if (acceptrics_telemetry_state() !== 'granted') {
        return;
    }

    $account_id  = get_option('acceptrics_account_id', '');
    $distinct_id = $account_id !== ''
        ? 'acct:' . $account_id
        : 'site:' . substr(hash('sha256', home_url()), 0, 16);

    $body = [
        'api_key'     => ACCEPTRICS_POSTHOG_KEY,
        'event'       => $event,
        'distinct_id' => $distinct_id,
        'timestamp'   => gmdate('c'),
        'properties'  => array_merge([
            'source'          => 'wp-plugin',
            'plugin_version'  => defined('ACCEPTRICS_VERSION') ? ACCEPTRICS_VERSION : 'unknown',
            'wp_version'      => get_bloginfo('version'),
            'php_version'     => PHP_VERSION,
            'site_host'       => wp_parse_url(home_url(), PHP_URL_HOST),
            'banner_connected' => $account_id !== '',
            'relay_status'    => get_option('acceptrics_relay_status', ''),
            'relay_mode'      => get_option('acceptrics_relay_mode', ''),
        ], $props),
    ];

    wp_remote_post(ACCEPTRICS_POSTHOG_HOST . '/capture/', [
        'timeout'  => 2,
        'blocking' => false,
        'headers'  => ['Content-Type' => 'application/json'],
        'body'     => wp_json_encode($body),
    ]);

    // Debug/support breadcrumb: last event we attempted to send.
    update_option('acceptrics_telemetry_last', $event . ' @ ' . gmdate('c'), false);
}

// ---------------------------------------------------------------------------
// Opt-in choice (AJAX from the settings-page prompt)
// ---------------------------------------------------------------------------
add_action('wp_ajax_acceptrics_telemetry_choice', function () {
    check_ajax_referer('acceptrics_telemetry_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    $choice = sanitize_text_field(wp_unslash($_POST['choice'] ?? ''));
    if (!in_array($choice, ['granted', 'denied'], true)) {
        wp_send_json_error('Invalid choice');
    }
    update_option('acceptrics_telemetry', $choice);

    if ($choice === 'granted') {
        // Backfill the events that happened before consent existed.
        acceptrics_track('wp_telemetry_opt_in');
        acceptrics_track('wp_plugin_activated', [
            'installed_at' => get_option('acceptrics_installed_at', ''),
            'backfilled'   => true,
        ]);
    }
    wp_send_json_success(['state' => $choice]);
});

// ---------------------------------------------------------------------------
// Event wiring — central option hooks catch every code path
// ---------------------------------------------------------------------------

// Account connected (manual paste or embedded signup). The embedded-signup
// handler tracks its own richer event and sets this flag to avoid doubles.
$GLOBALS['acceptrics_skip_account_event'] = false;

function acceptrics_on_account_option($old_value, $value) {
    if (!empty($GLOBALS['acceptrics_skip_account_event'])) {
        return;
    }
    if ($old_value === $value) {
        return;
    }
    if ($value !== '') {
        acceptrics_track('wp_account_connected', ['method' => 'manual']);
    } else {
        acceptrics_track('wp_account_disconnected');
    }
}
add_action('update_option_acceptrics_account_id', 'acceptrics_on_account_option', 10, 2);
add_action('add_option_acceptrics_account_id', function ($option, $value) {
    acceptrics_on_account_option('', $value);
}, 10, 2);

// Relay lifecycle — any transition to/from 'active'.
add_action('update_option_acceptrics_relay_status', function ($old_value, $value) {
    if ($old_value === $value) {
        return;
    }
    if ($value === 'active') {
        acceptrics_track('wp_relay_activated', ['relay_mode' => get_option('acceptrics_relay_mode', '')]);
    } elseif ($old_value === 'active') {
        acceptrics_track('wp_relay_deactivated', ['new_status' => $value]);
    }
}, 10, 2);

// Feature toggles.
foreach ([
    'acceptrics_blocker_detect_enabled' => 'wp_blocker_detection_toggled',
    'acceptrics_enable_banner'          => 'wp_consent_api_sync_toggled',
    'acceptrics_consent_mode_enabled'   => 'wp_google_consent_mode_toggled',
] as $acpt_option => $acpt_event) {
    add_action("update_option_{$acpt_option}", function ($old_value, $value) use ($acpt_event) {
        if ((bool) $old_value === (bool) $value) {
            return;
        }
        acceptrics_track($acpt_event, ['enabled' => (bool) $value]);
    }, 10, 2);
}

// Settings-page view, throttled to once per day.
add_action('current_screen', function ($screen) {
    if (!$screen || $screen->id !== 'settings_page_acceptrics-consent-banner') {
        return;
    }
    if (get_transient('acceptrics_pageview_sent')) {
        return;
    }
    set_transient('acceptrics_pageview_sent', 1, DAY_IN_SECONDS);
    acceptrics_track('wp_settings_page_viewed', [
        'tab' => isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings',
    ]);
});
