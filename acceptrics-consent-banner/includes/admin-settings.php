<?php
if (!defined('ABSPATH')) {
    exit;
}

// Account-creation endpoint (same Lambda the acceptrics.com wizard uses).
// Creates the account, generates the banner config, and emails the code.
if (!defined('ACCEPTRICS_ACCOUNT_API_URL')) {
    define('ACCEPTRICS_ACCOUNT_API_URL', 'https://guirl4277ftmhefw2fzuvwqpqe0zdtca.lambda-url.us-west-2.on.aws/');
}

function acceptrics_add_admin_menu() {
    add_options_page(
        'Acceptrics Consent Banner',
        'Acceptrics Consent Banner',
        'manage_options',
        'acceptrics-consent-banner',
        'acceptrics_settings_page'
    );
}
add_action('admin_menu', 'acceptrics_add_admin_menu');

function acceptrics_register_settings() {
    register_setting('acceptrics_settings_group', 'acceptrics_account_id', [
        'sanitize_callback' => 'acceptrics_sanitize_account_id',
    ]);
    register_setting('acceptrics_settings_group', 'acceptrics_enable_banner');
    register_setting('acceptrics_settings_group', 'acceptrics_blocker_detect_enabled');
    register_setting('acceptrics_settings_group', 'acceptrics_consent_mode_enabled');
}
add_action('admin_init', 'acceptrics_register_settings');

// AJAX: Save adaptive routing strategy from the admin active-state card.
add_action('wp_ajax_acceptrics_save_relay_strategy', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    $strategy = sanitize_text_field(wp_unslash($_POST['strategy'] ?? ''));
    if (!in_array($strategy, ['adaptive', 'always_relay', 'always_direct'], true)) {
        wp_send_json_error('Invalid strategy.');
    }
    update_option('acceptrics_relay_strategy', $strategy);
    wp_send_json_success(['strategy' => $strategy]);
});

function acceptrics_sanitize_account_id($value) {
    $value = sanitize_text_field($value);
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $value);
}

// AJAX: Create an Acceptrics account from the settings page for customers who
// installed the plugin without one. Calls the account Lambda server-side (no
// CORS exposure), saves the returned code, and enables the banner.
add_action('wp_ajax_acceptrics_create_account', 'acceptrics_handle_create_account');
function acceptrics_handle_create_account() {
    check_ajax_referer('acceptrics_create_account_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized.']);
    }
    // Idempotence guard: never overwrite an already-configured account.
    if (get_option('acceptrics_account_id', '') !== '') {
        wp_send_json_error(['message' => 'An account code is already configured. Remove it first if you want to create a new account.']);
    }

    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    if (!is_email($email)) {
        wp_send_json_error(['message' => 'Please enter a valid email address.']);
    }

    $geo_area = sanitize_text_field(wp_unslash($_POST['geo_area'] ?? 'eea'));
    if (!in_array($geo_area, ['eea', 'worldwide'], true)) {
        $geo_area = 'eea';
    }

    // Mirror the acceptrics.com wizard defaults so a plugin-created account
    // behaves identically to a wizard-created one.
    $document = [
        'gcmAdvanced'           => true,
        'backgroundColor'       => '',
        'fontColor'             => '',
        'geoArea'               => $geo_area,
        'respectGPC'            => true,
        'useTranslation'        => true,
        'bannerStyle'           => '',
        'ec'                    => false,
        'autoIncludeAllCookies' => true,
        'theme'                 => 'theme-default',
    ];

    $response = wp_remote_post(ACCEPTRICS_ACCOUNT_API_URL, [
        'timeout' => 20,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode(['email' => $email, 'document' => $document]),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Could not reach Acceptrics. Please check your connection and try again.']);
    }

    $status = wp_remote_retrieve_response_code($response);
    $data   = json_decode(wp_remote_retrieve_body($response), true);

    if (is_array($data) && ($data['message'] ?? '') === 'Conflict') {
        wp_send_json_error(['message' =>
            'This email is already registered. Your account code is in your welcome email, '
            . 'or <a href="https://acceptrics.com/auth/login" target="_blank" rel="noopener">log in to your account</a> to retrieve it.'
        ]);
    }
    if ($status === 429) {
        wp_send_json_error(['message' => 'Too many attempts. Please wait a few seconds and try again.']);
    }
    if ($status !== 200 || !is_array($data) || empty($data['accountNum'])) {
        wp_send_json_error(['message' =>
            'Account creation failed. Please try again, or '
            . '<a href="https://acceptrics.com/wizard" target="_blank" rel="noopener">sign up at acceptrics.com</a>.'
        ]);
    }

    $account_id = acceptrics_sanitize_account_id($data['accountNum']);
    if ($account_id === '') {
        wp_send_json_error(['message' => 'Received an invalid account code. Please try again.']);
    }

    $GLOBALS['acceptrics_skip_account_event'] = true;
    update_option('acceptrics_account_id', $account_id);
    update_option('acceptrics_enable_banner', 1);
    // Remember the region so the Status card can explain where the banner shows.
    update_option('acceptrics_geo_area', $geo_area);
    $GLOBALS['acceptrics_skip_account_event'] = false;
    acceptrics_track('wp_account_created', ['method' => 'embedded_signup', 'geo_area' => $geo_area]);

    wp_send_json_success(['accountNum' => $account_id]);
}

function acceptrics_is_wp_consent_api_active() {
    include_once ABSPATH . 'wp-admin/includes/plugin.php';
    return is_plugin_active('wp-consent-api/wp-consent-api.php');
}

// Enqueue relay-setup.js only on our settings page.
function acceptrics_enqueue_relay_scripts($hook) {
    if ($hook !== 'settings_page_acceptrics-consent-banner') {
        return;
    }
    wp_enqueue_script(
        'acceptrics-relay-setup',
        ACCEPTRICS_PLUGIN_URL . 'includes/js/relay-setup.js',
        ['jquery'],
        ACCEPTRICS_VERSION,
        true
    );
    wp_localize_script(
        'acceptrics-relay-setup',
        'acceptricsRelayData',
        acceptrics_relay_get_js_data()
    );
    wp_enqueue_script(
        'acceptrics-account-create',
        ACCEPTRICS_PLUGIN_URL . 'includes/js/account-create.js',
        ['jquery'],
        ACCEPTRICS_VERSION,
        true
    );
    wp_localize_script(
        'acceptrics-account-create',
        'acceptricsCreateData',
        [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('acceptrics_create_account_nonce'),
            'telemetryNonce' => wp_create_nonce('acceptrics_telemetry_nonce'),
        ]
    );
}
add_action('admin_enqueue_scripts', 'acceptrics_enqueue_relay_scripts');

// ---------------------------------------------------------------------------
// Settings page
// ---------------------------------------------------------------------------

function acceptrics_settings_page() {
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';

    $is_consent_api_active = acceptrics_is_wp_consent_api_active();
    $account_id            = get_option('acceptrics_account_id', '');
    $enable_banner         = get_option('acceptrics_enable_banner', false);
    $geo_area              = get_option('acceptrics_geo_area', '');
    $site_host_display     = wp_parse_url(home_url(), PHP_URL_HOST);
    $plugin_install_url    = admin_url('plugin-install.php?s=WP+Consent+API&tab=search&type=term');
    $geoip_active          = function_exists('geoip_detect2_get_info_from_current_ip');
    $geoip_install_url     = current_user_can('install_plugins')
        ? wp_nonce_url(admin_url('update.php?action=install-plugin&plugin=geoip-detect'), 'install-plugin_geoip-detect')
        : admin_url('plugin-install.php?s=geoip+detect&tab=search&type=term');
    $wizard_url            = 'https://acceptrics.com/wizard';

    // Relay state data (for PHP-rendered active/dns views)
    $relay_state        = acceptrics_relay_get_wizard_state();
    $relay_tag_id       = get_option('acceptrics_relay_tag_id', '');
    $relay_hostname     = get_option('acceptrics_relay_hostname', '');
    $relay_cf_domain    = get_option('acceptrics_relay_cf_domain', '');
    $relay_cert_short   = get_option('acceptrics_relay_cert_name_short', '');
    $relay_cert_val     = get_option('acceptrics_relay_cert_value', '');
    $relay_subdomain    = get_option('acceptrics_relay_hostname_short', 't');
    $relay_activated_at = get_option('acceptrics_relay_activated_at', '');
    $relay_meas_path    = get_option('acceptrics_relay_measurement_path', '/t');
    $relay_tag_ids      = get_option('acceptrics_relay_tag_ids', []);
    if (empty($relay_tag_ids) && !empty($relay_tag_id)) {
        $relay_tag_ids = [$relay_tag_id];
    }
    $site_domain        = function_exists('acceptrics_get_site_domain') ? acceptrics_get_site_domain() : '';
    $token_connected    = !empty(get_option('acceptrics_api_token', ''));

    // Blocker detection settings + report stats
    $detect_enabled        = (bool) get_option('acceptrics_blocker_detect_enabled', false);
    $consent_mode_enabled  = (bool) get_option('acceptrics_consent_mode_enabled', true);
    $detect_stats   = (array) get_option('acceptrics_detect_daily', []);
    krsort($detect_stats);
    $detect_recent     = array_slice($detect_stats, 0, 7, true);
    $det_total_b       = 0;
    $det_total_u       = 0;
    foreach ($detect_recent as $_d) {
        $det_total_b += $_d['blocked'];
        $det_total_u += $_d['unblocked'];
    }
    $det_total_sampled = $det_total_b + $det_total_u;
    $det_blocker_pct   = $det_total_sampled > 0 ? round(($det_total_b / $det_total_sampled) * 100) : null;
    $det_days          = count($detect_recent);
    $det_avg_b         = ($det_days > 0) ? round(($det_total_b / $det_days) * 10) : null;

    // Format activated date for display
    $relay_activated_display = '';
    if ($relay_activated_at) {
        $ts = strtotime($relay_activated_at);
        $relay_activated_display = $ts ? date_i18n('M j, Y', $ts) : '';
    }
    ?>
    <style>
        /* ---- existing card + layout styles ---- */
        #acceptrics-admin {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            max-width: 760px;
            margin: 24px 0;
            color: #333;
        }
        #acceptrics-admin .acpt-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }
        #acceptrics-admin .acpt-header img { height: 36px; width: auto; }
        #acceptrics-admin .acpt-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: #333; }
        #acceptrics-admin .acpt-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 24px 28px;
            margin-bottom: 20px;
        }
        #acceptrics-admin .acpt-card h2 { margin: 0 0 6px; font-size: 16px; font-weight: 600; color: #333; }
        #acceptrics-admin .acpt-status-grid { display: flex; flex-direction: column; gap: 14px; }
        #acceptrics-admin .acpt-status-item { display: flex; gap: 10px; align-items: flex-start; }
        #acceptrics-admin .acpt-status-item strong { font-size: 13px; color: #333; }
        #acceptrics-admin .acpt-status-item-desc { font-size: 12px; color: #888; margin-top: 2px; }
        #acceptrics-admin .acpt-dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 4px; flex-shrink: 0; }
        #acceptrics-admin .acpt-dot.on  { background: #4CAF50; box-shadow: 0 0 0 3px #e8f5e9; }
        #acceptrics-admin .acpt-dot.off { background: #cfcfcf; box-shadow: 0 0 0 3px #f4f4f4; }
        #acceptrics-admin .acpt-create-geo { margin: 12px 0; }
        #acceptrics-admin .acpt-create-geo label { display: block; margin-bottom: 8px; }
        #acceptrics-admin .acpt-geo-hint { display: block; margin-left: 24px; font-size: 12px; color: #888; }
        #acceptrics-admin .acpt-create-terms { margin-top: 12px; }
        #acceptrics-admin .acpt-status-ok { color: #1a7a2e; font-weight: 600; }
        #acceptrics-admin .acpt-status-err { color: #b00020; font-weight: 600; }
        #acceptrics-admin .acpt-card .acpt-card-desc { margin: 0 0 18px; font-size: 13px; color: #666; }
        #acceptrics-admin .acpt-card .acpt-card-desc a { color: #4CAF50; text-decoration: none; }
        #acceptrics-admin .acpt-card .acpt-card-desc a:hover { text-decoration: underline; }
        #acceptrics-admin .acpt-field-row {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        #acceptrics-admin .acpt-field-row input[type="text"],
        #acceptrics-admin .acpt-field-row input[type="password"] {
            flex: 1; min-width: 200px; max-width: 340px;
            padding: 9px 12px; border: 1.5px solid #ccc; border-radius: 6px;
            font-size: 14px; font-family: monospace; letter-spacing: 0.04em;
            outline: none; transition: border-color 0.15s;
        }
        #acceptrics-admin .acpt-field-row input:focus {
            border-color: #4CAF50; box-shadow: 0 0 0 2px rgba(76,175,80,0.15);
        }
        #acceptrics-admin .acpt-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;
        }
        #acceptrics-admin .acpt-badge.active  { background: #e8f5e9; color: #2e7d32; }
        #acceptrics-admin .acpt-badge.inactive { background: #fce4ec; color: #c62828; }
        #acceptrics-admin .acpt-status-row {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 10px;
        }
        #acceptrics-admin .acpt-toggle-row {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 0; border-bottom: 1px solid #f0f0f0;
        }
        #acceptrics-admin .acpt-toggle-row:last-child { border-bottom: none; padding-bottom: 0; }
        #acceptrics-admin .acpt-toggle-row label { font-size: 14px; font-weight: 500; cursor: pointer; }
        #acceptrics-admin .acpt-toggle-row .acpt-toggle-desc { font-size: 12px; color: #888; margin-top: 2px; }
        #acceptrics-admin .acpt-toggle-row .acpt-toggle-desc a { color: #4CAF50; }
        #acceptrics-admin .acpt-hint { margin-top: 10px; font-size: 12px; color: #888; }
        #acceptrics-admin .acpt-hint a { color: #4CAF50; }
        #acceptrics-admin .acpt-preview {
            background: #f9f9f9; border: 1px dashed #ccc; border-radius: 6px;
            padding: 10px 14px; font-size: 12px; font-family: monospace;
            color: #555; margin-top: 14px; word-break: break-all;
        }
        #acceptrics-admin .acpt-preview span.acpt-highlight { color: #4CAF50; font-weight: bold; }
        #acceptrics-admin .button-primary {
            background: #4CAF50 !important; border-color: #43a047 !important;
            color: #fff !important; padding: 8px 22px !important;
            border-radius: 6px !important; font-size: 14px !important;
            font-weight: 600 !important; box-shadow: none !important;
            text-shadow: none !important; cursor: pointer;
        }
        #acceptrics-admin .button-primary:hover { background: #43a047 !important; }
        #acceptrics-admin .acpt-install-btn {
            display: inline-block; padding: 6px 14px; background: #fff;
            border: 1.5px solid #4CAF50; color: #4CAF50; border-radius: 6px;
            font-size: 12px; font-weight: 600; text-decoration: none;
        }
        #acceptrics-admin .acpt-install-btn:hover { background: #e8f5e9; }

        /* ---- Relay wizard styles ---- */
        .acpt-relay-step { display: none; } /* JS shows the active one */

        .acpt-relay-header {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 8px; margin-bottom: 6px;
        }
        .acpt-relay-header h2 { margin: 0; font-size: 16px; font-weight: 600; }
        .acpt-relay-beta {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; background: #e8eaf6; color: #3949ab;
            padding: 2px 8px; border-radius: 20px;
        }
        .acpt-token-connected {
            font-size: 12px; color: #2e7d32; background: #e8f5e9;
            padding: 3px 10px; border-radius: 20px; display: inline-flex;
            align-items: center; gap: 4px; margin-bottom: 16px;
        }
        .acpt-btn-disconnect {
            background: none; border: none; padding: 0;
            color: #999; font-size: 11px; cursor: pointer;
            text-decoration: underline; margin-left: 8px;
        }
        .acpt-btn-disconnect:hover { color: #c62828; }
        .acpt-relay-step .acpt-field-label {
            display: block; font-size: 13px; font-weight: 500;
            color: #444; margin-bottom: 5px; margin-top: 14px;
        }
        .acpt-relay-step .acpt-field-label:first-child { margin-top: 0; }
        .acpt-subdomain-row {
            display: flex; align-items: center; gap: 0;
        }
        .acpt-subdomain-row input {
            max-width: 120px !important; border-radius: 6px 0 0 6px !important;
            border-right: none !important;
        }
        .acpt-subdomain-domain {
            padding: 9px 12px; background: #f5f5f5; border: 1.5px solid #ccc;
            border-left: none; border-radius: 0 6px 6px 0; font-size: 14px;
            font-family: monospace; color: #888; white-space: nowrap;
        }
        .acpt-btn-primary {
            display: inline-block; padding: 9px 20px; background: #4CAF50;
            color: #fff; border: none; border-radius: 6px; font-size: 14px;
            font-weight: 600; cursor: pointer; margin-top: 16px;
            transition: background 0.15s;
        }
        .acpt-btn-primary:hover:not(:disabled) { background: #43a047; }
        .acpt-btn-primary:disabled { background: #a5d6a7; cursor: not-allowed; }
        .acpt-btn-secondary {
            display: inline-block; padding: 8px 18px; background: #fff;
            color: #555; border: 1.5px solid #ccc; border-radius: 6px;
            font-size: 13px; font-weight: 500; cursor: pointer;
            margin-top: 10px; transition: background 0.15s;
        }
        .acpt-btn-secondary:hover { background: #f5f5f5; }
        .acpt-error-msg {
            display: none; margin-top: 10px; font-size: 13px; color: #c62828;
            background: #fce4ec; padding: 8px 12px; border-radius: 6px;
        }

        /* DNS record copy boxes */
        .acpt-record-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .acpt-record-table th {
            text-align: left; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em;
            color: #999; padding: 0 10px 6px 0;
        }
        .acpt-record-table td {
            padding: 6px 10px 6px 0; font-size: 13px;
            border-top: 1px solid #f0f0f0; vertical-align: middle;
        }
        .acpt-record-table .acpt-record-val {
            font-family: monospace; font-size: 12px; color: #333;
            word-break: break-all;
        }
        .acpt-record-table .acpt-type-badge {
            font-size: 11px; font-weight: 700; background: #e3f2fd;
            color: #1565c0; padding: 2px 7px; border-radius: 4px;
        }
        .acpt-copy-btn {
            padding: 4px 10px; font-size: 12px; background: #fff;
            border: 1px solid #ccc; border-radius: 4px; cursor: pointer;
            white-space: nowrap; transition: background 0.1s;
        }
        .acpt-copy-btn:hover { background: #f0f0f0; }
        .acpt-copy-btn.acpt-copied { background: #e8f5e9; border-color: #4CAF50; color: #2e7d32; }

        /* Provider steps */
        .acpt-provider-badge {
            display: inline-block; font-size: 12px; color: #555;
            background: #f5f5f5; border: 1px solid #e0e0e0;
            padding: 4px 12px; border-radius: 20px; margin-bottom: 12px;
        }
        .acpt-dns-steps { padding-left: 20px; margin: 0; }
        .acpt-dns-steps li { font-size: 13px; color: #444; margin-bottom: 8px; line-height: 1.5; }
        .acpt-dns-steps li code {
            background: #f5f5f5; border: 1px solid #e0e0e0;
            padding: 1px 5px; border-radius: 3px; font-size: 12px;
        }

        /* Propagation status rows */
        .acpt-poll-section { margin-top: 20px; }
        .acpt-poll-row {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 6px; margin-bottom: 8px;
            background: #fafafa; border: 1px solid #eeeeee;
            font-size: 13px;
        }
        .acpt-poll-row.acpt-row-done  { background: #e8f5e9; border-color: #a5d6a7; }
        .acpt-poll-row.acpt-row-pending { background: #fafafa; }
        .acpt-check { color: #2e7d32; font-size: 16px; font-weight: 700; }
        @keyframes acpt-spin { to { transform: rotate(360deg); } }
        .acpt-spin {
            display: inline-block; width: 14px; height: 14px;
            border: 2px solid #ccc; border-top-color: #4CAF50;
            border-radius: 50%; animation: acpt-spin 0.7s linear infinite;
            flex-shrink: 0;
        }
        .acpt-poll-label { flex: 1; }
        .acpt-poll-hint  { font-size: 11px; color: #999; margin-top: 2px; }
        .acpt-poll-note  { font-size: 12px; color: #888; margin-top: 10px; }

        /* Tag list */
        .acpt-tag-list { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 14px; min-height: 32px; }
        .acpt-tag-pill {
            display: inline-flex; align-items: center; gap: 6px;
            background: #e3f2fd; border: 1px solid #90caf9; border-radius: 20px;
            padding: 3px 10px 3px 12px; font-family: monospace; font-size: 12px; color: #1565c0;
        }
        .acpt-tag-pill.acpt-tag-primary { background: #e8f5e9; border-color: #a5d6a7; color: #2e7d32; }
        .acpt-tag-pill-remove {
            background: none; border: none; cursor: pointer; padding: 0;
            color: #999; font-size: 14px; line-height: 1; display: inline-flex; align-items: center;
        }
        .acpt-tag-pill-remove:hover { color: #c62828; }
        .acpt-add-tag-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .acpt-add-tag-row input {
            flex: 1; min-width: 160px; max-width: 240px;
            padding: 7px 10px; border: 1.5px solid #ccc; border-radius: 6px;
            font-size: 13px; font-family: monospace; outline: none;
        }
        .acpt-add-tag-row input:focus { border-color: #4CAF50; box-shadow: 0 0 0 2px rgba(76,175,80,0.15); }

        /* Active state */
        .acpt-active-header {
            display: flex; align-items: center; gap: 10px; margin-bottom: 8px;
        }
        .acpt-active-icon { font-size: 22px; }
        .acpt-active-hostname { font-family: monospace; color: #2e7d32; font-weight: 600; }
        .acpt-snippet-box {
            background: #f9f9f9; border: 1px dashed #ccc; border-radius: 6px;
            padding: 12px 14px; font-size: 11px; font-family: monospace;
            color: #555; margin-top: 14px; white-space: pre-wrap; overflow-x: auto;
            word-break: break-word; max-width: 100%;
        }
        .acpt-divider { border: none; border-top: 1px solid #f0f0f0; margin: 20px 0; }

        /* Step number pill */
        .acpt-step-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 50%;
            background: #4CAF50; color: #fff; font-size: 12px;
            font-weight: 700; flex-shrink: 0; margin-right: 6px;
        }
        .acpt-step-heading {
            display: flex; align-items: center; margin: 0 0 4px;
            font-size: 16px; font-weight: 600; color: #333;
        }

        /* Mode-select cards */
        .acpt-mode-cards { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 18px; }
        .acpt-mode-card {
            flex: 1; min-width: 200px; border: 2px solid #e0e0e0; border-radius: 8px;
            padding: 18px 20px; cursor: pointer; transition: border-color 0.15s, background 0.15s;
            background: #fff;
        }
        .acpt-mode-card:hover { border-color: #4CAF50; background: #f9fef9; }
        .acpt-mode-card.acpt-mode-selected { border-color: #4CAF50; background: #f1fbf1; }
        .acpt-mode-card-icon { font-size: 26px; margin-bottom: 8px; }
        .acpt-mode-card-title { font-size: 15px; font-weight: 600; color: #333; margin-bottom: 4px; }
        .acpt-mode-card-desc { font-size: 12px; color: #666; line-height: 1.5; }
        .acpt-mode-card-badge {
            display: inline-block; margin-top: 8px; font-size: 11px; font-weight: 600;
            padding: 2px 8px; border-radius: 10px;
        }
        .acpt-mode-card-badge.badge-cdn { background: #e3f2fd; color: #1565c0; }
        .acpt-mode-card-badge.badge-server { background: #fff8e1; color: #f57f17; }

        /* Path input row */
        .acpt-path-row { display: flex; align-items: center; gap: 0; margin-top: 6px; }
        .acpt-path-origin {
            padding: 9px 10px; background: #f5f5f5; border: 1.5px solid #ccc;
            border-right: none; border-radius: 6px 0 0 6px; font-size: 13px;
            font-family: monospace; color: #888; white-space: nowrap;
        }
        .acpt-path-row input {
            flex: 1; min-width: 100px; max-width: 160px;
            padding: 9px 10px; border: 1.5px solid #ccc;
            border-radius: 0 6px 6px 0; font-size: 13px; font-family: monospace;
            outline: none; transition: border-color 0.15s;
        }
        .acpt-path-row input:focus { border-color: #4CAF50; box-shadow: 0 0 0 2px rgba(76,175,80,0.15); }

        /* Health badge */
        .acpt-health-badge {
            display: inline-flex; align-items: center; gap: 5px; font-size: 12px;
            font-weight: 600; padding: 3px 10px; border-radius: 20px;
        }
        .acpt-health-badge.healthy   { background: #e8f5e9; color: #2e7d32; }
        .acpt-health-badge.degraded  { background: #fff3e0; color: #e65100; }
        .acpt-health-badge.unknown   { background: #f5f5f5; color: #999; }
        .acpt-health-dot {
            width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
        }
        .acpt-health-badge.healthy .acpt-health-dot  { background: #4CAF50; }
        .acpt-health-badge.degraded .acpt-health-dot { background: #ef6c00; }
        .acpt-health-badge.unknown .acpt-health-dot  { background: #bbb; }

        /* Back-to-mode-select link */
        .acpt-back-link {
            display: inline-flex; align-items: center; gap: 4px; font-size: 12px;
            color: #999; cursor: pointer; background: none; border: none; padding: 0;
            margin-bottom: 16px; text-decoration: none;
        }
        .acpt-back-link:hover { color: #4CAF50; }

        /* GeoIP Detect status row */
        .acpt-geo-notice {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            background: #f9fef9; border: 1px solid #c8e6c9; border-radius: 6px;
            padding: 12px 14px;
        }

        /* Relay mode badge shown in active header */
        .acpt-mode-badge {
            font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .acpt-mode-badge.dns  { background: #e3f2fd; color: #1565c0; }
        .acpt-mode-badge.path { background: #fff8e1; color: #f57f17; }

        /* ---- Page tab navigation ---- */
        #acceptrics-admin .acpt-tab-nav {
            display: flex; gap: 0; margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }
        #acceptrics-admin .acpt-tab-nav a {
            display: inline-block; padding: 10px 20px;
            font-size: 14px; font-weight: 500; color: #555;
            text-decoration: none; border-bottom: 2px solid transparent;
            margin-bottom: -2px; transition: color 0.15s;
        }
        #acceptrics-admin .acpt-tab-nav a:hover { color: #4CAF50; }
        #acceptrics-admin .acpt-tab-nav a.active { color: #4CAF50; border-bottom-color: #4CAF50; font-weight: 600; }

        /* ---- Detect report ---- */
        .acpt-detect-summary {
            background: #f1fbf1; border: 1px solid #c8e6c9; border-radius: 8px;
            padding: 24px; margin-bottom: 20px; text-align: center;
        }
        .acpt-detect-big-num {
            font-size: 52px; font-weight: 700; color: #2e7d32; line-height: 1;
        }
        .acpt-detect-big-label { font-size: 16px; color: #444; margin: 8px 0 4px; }
        .acpt-detect-big-desc  { font-size: 13px; color: #666; }
        .acpt-detect-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .acpt-detect-table th {
            text-align: left; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em;
            color: #999; padding: 0 10px 6px 0;
        }
        .acpt-detect-table td {
            padding: 8px 10px 8px 0; font-size: 13px;
            border-top: 1px solid #f0f0f0; color: #333;
        }
        .acpt-detect-empty {
            text-align: center; padding: 40px 28px; color: #888;
        }
        .acpt-detect-empty .acpt-detect-empty-icon { font-size: 36px; margin-bottom: 12px; }
        .acpt-detect-empty .acpt-detect-empty-title { font-size: 15px; font-weight: 600; color: #555; margin-bottom: 6px; }
    </style>

    <div id="acceptrics-admin">

        <div class="acpt-header">
            <img src="<?php echo esc_url(ACCEPTRICS_PLUGIN_URL . 'includes/assets/logo.png'); ?>" alt="Acceptrics" />
            <h1>Acceptrics Consent Banner</h1>
        </div>

        <nav class="acpt-tab-nav">
            <a href="<?php echo esc_url(admin_url('options-general.php?page=acceptrics-consent-banner&tab=settings')); ?>"
               class="<?php echo ($active_tab === 'settings') ? 'active' : ''; ?>">Settings</a>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=acceptrics-consent-banner&tab=report')); ?>"
               class="<?php echo ($active_tab === 'report') ? 'active' : ''; ?>">Analytics Report</a>
        </nav>

        <?php if ($active_tab === 'settings') : ?>

        <?php if (acceptrics_telemetry_state() === '') : ?>
        <!-- One-time telemetry opt-in prompt (wp.org: informed consent required) -->
        <div class="acpt-card" id="acpt-telemetry-prompt" style="border-left:3px solid #B469B5;">
            <p class="acpt-card-desc" style="margin:0 0 10px;">
                <strong>Share anonymous usage data?</strong>
                Help improve this plugin by sending product events &mdash; activation, setup steps
                completed, WordPress/PHP version, and your site domain &mdash; to Acceptrics analytics.
                <strong>Never anything about your visitors.</strong>
                <a href="https://acceptrics.com/assets/policy.pdf" target="_blank" rel="noopener">Privacy Policy</a>
            </p>
            <button type="button" class="button button-primary" id="acpt-tel-allow">Allow</button>
            <button type="button" class="button" id="acpt-tel-deny" style="margin-left:6px;">No thanks</button>
        </div>
        <?php endif; ?>

        <?php if (empty($account_id)) : ?>
        <!-- ============================================================
             Embedded account creation (no account configured yet)
             ============================================================ -->
        <div class="acpt-card" id="acpt-create-account-card">
            <h2>New to Acceptrics? Create your free account</h2>
            <p class="acpt-card-desc">
                Create an account right here — your banner code is generated and saved
                automatically, and the consent banner goes live on your site.
                Free up to 50,000 page views/month, no credit card required.
            </p>
            <div class="acpt-field-row">
                <input
                    type="email"
                    id="acpt-create-email"
                    value="<?php echo esc_attr(get_option('admin_email', '')); ?>"
                    placeholder="you@example.com"
                    autocomplete="email"
                    spellcheck="false"
                />
            </div>
            <div class="acpt-create-geo">
                <label>
                    <input type="radio" name="acpt_create_geo" value="eea" checked />
                    Show the banner to EU/EEA visitors only
                    <span class="acpt-geo-hint">recommended for most sites; visitors elsewhere browse without a prompt</span>
                </label>
                <label>
                    <input type="radio" name="acpt_create_geo" value="worldwide" />
                    Show the banner to all visitors worldwide
                </label>
            </div>
            <p class="acpt-hint" id="acpt-create-status" role="status" aria-live="polite"></p>
            <button type="button" class="button button-primary" id="acpt-create-btn">Create account &amp; enable banner</button>
            <p class="acpt-hint acpt-create-terms">
                By creating an account you agree to the
                <a href="https://acceptrics.com/assets/terms.pdf" target="_blank" rel="noopener">Terms of Service</a> and
                <a href="https://acceptrics.com/assets/policy.pdf" target="_blank" rel="noopener">Privacy Policy</a>.
                Your banner code will also be emailed to you. You can customize the banner anytime at
                <a href="https://acceptrics.com/auth/login" target="_blank" rel="noopener">acceptrics.com</a>.
            </p>
        </div>
        <?php endif; ?>

        <!-- ============================================================
             Standard settings (account code + WP Consent API status)
             ============================================================ -->
        <form method="post" action="options.php">
            <?php settings_fields('acceptrics_settings_group'); ?>

            <!-- Account Code Card -->
            <div class="acpt-card">
                <h2>Account Code</h2>
                <p class="acpt-card-desc">
                    Enter the account code from your
                    <a href="<?php echo esc_url($wizard_url); ?>" target="_blank">Acceptrics account</a>.
                    This enables your consent banner on every page of your site.
                </p>
                <div class="acpt-field-row">
                    <input
                        type="text"
                        name="acceptrics_account_id"
                        value="<?php echo esc_attr($account_id); ?>"
                        placeholder="e.g. jl30o0uj"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </div>
                <?php if (!empty($account_id)) : ?>
                    <div class="acpt-preview">
                        &lt;script async src="https://acct.acceptrics.com/<span class="acpt-highlight"><?php echo esc_html($account_id); ?></span>"&gt;&lt;/script&gt;
                    </div>
                    <p class="acpt-hint">This script is injected into the &lt;head&gt; of every page on your site.</p>
                <?php else : ?>
                    <p class="acpt-hint">
                        Don't have an account yet? Create one in the card above,
                        or <a href="<?php echo esc_url($wizard_url); ?>" target="_blank">sign up at acceptrics.com/wizard</a>.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Status card -->
            <div class="acpt-card">
                <h2>Status</h2>
                <div class="acpt-status-grid">
                    <div class="acpt-status-item">
                        <span class="acpt-dot <?php echo $account_id ? 'on' : 'off'; ?>"></span>
                        <div>
                            <strong>Consent banner</strong>
                            <div class="acpt-status-item-desc">
                                <?php if ($account_id && $geo_area === 'eea') : ?>
                                    Live on <?php echo esc_html($site_host_display); ?> for EU/EEA visitors.
                                    Browsing from outside the EU, you may not see it yourself.
                                <?php elseif ($account_id && $geo_area === 'worldwide') : ?>
                                    Live on <?php echo esc_html($site_host_display); ?> for all visitors.
                                <?php elseif ($account_id) : ?>
                                    Live on every page of <?php echo esc_html($site_host_display); ?>.
                                <?php else : ?>
                                    Not connected &mdash; create an account or enter your account code above.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="acpt-status-item">
                        <span class="acpt-dot <?php echo $consent_mode_enabled ? 'on' : 'off'; ?>"></span>
                        <div>
                            <strong>Google Consent Mode</strong>
                            <div class="acpt-status-item-desc">
                                <?php echo $consent_mode_enabled
                                    ? 'On &mdash; consent defaults set to denied before Google tags load.'
                                    : 'Off &mdash; Acceptrics is not sending consent signals to Google tags.'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="acpt-status-item">
                        <span class="acpt-dot <?php echo ($is_consent_api_active && $enable_banner) ? 'on' : 'off'; ?>"></span>
                        <div>
                            <strong>WP Consent API sync</strong>
                            <div class="acpt-status-item-desc">
                                <?php if (!$is_consent_api_active) : ?>
                                    WP Consent API plugin not installed &mdash; consent choices stay within Acceptrics.
                                <?php elseif (!$enable_banner) : ?>
                                    Installed, sync off &mdash; enable it below to share consent with other plugins.
                                <?php else : ?>
                                    Syncing consent choices to other plugins.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="acpt-status-item">
                        <span class="acpt-dot <?php echo ($relay_state === 'active') ? 'on' : 'off'; ?>"></span>
                        <div>
                            <strong>Tag Relay (Analytics Recovery)</strong>
                            <div class="acpt-status-item-desc">
                                <?php if ($relay_state === 'active') : ?>
                                    Active &mdash; see the <a href="<?php echo esc_url(admin_url('options-general.php?page=acceptrics-consent-banner&tab=report')); ?>">Analytics Report</a> tab for recovery data.
                                <?php else : ?>
                                    Not active &mdash; optional; set it up in Analytics Recovery below.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Integrations Card -->
            <div class="acpt-card">
                <h2>Integrations</h2>
                <?php if ($is_consent_api_active) : ?>
                <div class="acpt-toggle-row">
                    <input type="checkbox" name="acceptrics_enable_banner" id="acceptrics_enable_banner" value="1" <?php checked(1, $enable_banner); ?> style="margin-top:2px;" />
                    <div>
                        <label for="acceptrics_enable_banner">Sync consent with other plugins (WP Consent API)</label>
                        <div class="acpt-toggle-desc">
                            Bridges your visitors' Acceptrics consent choices to the WP Consent API so
                            other plugins can respect them. The banner itself is controlled by the
                            account code above &mdash; it shows whenever a code is saved.
                        </div>
                    </div>
                </div>
                <?php else : ?>
                <?php /* Preserve the saved sync preference while the checkbox is not rendered —
                         options.php writes every registered setting on save, so an absent field
                         would silently wipe the option. */ ?>
                <input type="hidden" name="acceptrics_enable_banner" value="<?php echo $enable_banner ? '1' : ''; ?>" />
                <div class="acpt-toggle-row">
                    <div style="flex:1;">
                        <label>Sync consent with other plugins (WP Consent API)</label>
                        <div class="acpt-toggle-desc">
                            Bridges your visitors' Acceptrics consent choices to the WP Consent API so
                            other plugins can respect them. Install the free WP Consent API plugin to
                            enable syncing. The banner itself works without it.
                        </div>
                    </div>
                    <a href="<?php echo esc_url($plugin_install_url); ?>" class="acpt-install-btn" style="align-self:center;flex-shrink:0;">Install Plugin</a>
                </div>
                <?php endif; ?>
                <div class="acpt-toggle-row">
                    <input type="checkbox" name="acceptrics_blocker_detect_enabled" id="acceptrics_blocker_detect_enabled"
                           value="1" <?php checked(1, $detect_enabled); ?> style="margin-top:2px;" />
                    <div>
                        <label for="acceptrics_blocker_detect_enabled">Enable blocker detection</label>
                        <div class="acpt-toggle-desc">
                            Samples 10% of page loads to estimate how many visitors have ad blockers.
                            Results appear in the <a href="<?php echo esc_url(admin_url('options-general.php?page=acceptrics-consent-banner&tab=report')); ?>">Analytics Report</a> tab.
                            Adds ~1ms on sampled visits only.
                        </div>
                    </div>
                </div>
                <?php $acpt_tel_state = acceptrics_telemetry_state(); ?>
                <?php if ($acpt_tel_state !== '') : ?>
                <div class="acpt-toggle-row">
                    <div style="flex:1;">
                        <label>Anonymous usage data</label>
                        <div class="acpt-toggle-desc">
                            Currently <strong><?php echo $acpt_tel_state === 'granted' ? 'on' : 'off'; ?></strong> &mdash;
                            product events only (setup steps, versions, site domain), never visitor data.
                            <a href="#" id="acpt-tel-toggle" data-next="<?php echo $acpt_tel_state === 'granted' ? 'denied' : 'granted'; ?>">
                                Turn <?php echo $acpt_tel_state === 'granted' ? 'off' : 'on'; ?></a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="acpt-toggle-row">
                    <input type="checkbox" name="acceptrics_consent_mode_enabled" id="acceptrics_consent_mode_enabled"
                           value="1" <?php checked(1, $consent_mode_enabled); ?> style="margin-top:2px;" />
                    <div>
                        <label for="acceptrics_consent_mode_enabled">Enable Google Consent Mode</label>
                        <div class="acpt-toggle-desc">
                            Sets consent defaults to <code>denied</code> before Google tags load, then updates
                            when the visitor makes a choice. Disable only if you manage consent signals yourself
                            and do not want Acceptrics to call <code>gtag('consent', 'default', &hellip;)</code>.
                        </div>
                    </div>
                </div>

            </div>

            <?php submit_button('Save Banner Settings', 'primary', 'submit', true); ?>
        </form>

        <!-- ============================================================
             Tag Relay wizard — all steps rendered; JS shows the active one
             ============================================================ -->
        <div class="acpt-card" id="acpt-relay-card">

            <div class="acpt-relay-header">
                <h2>Analytics Recovery</h2>
                <span class="acpt-relay-beta">Beta</span>
            </div>
            <p class="acpt-card-desc" style="margin-bottom:0;">
                Recover analytics and conversion data lost to ad blockers and browser restrictions.
                Tags load through your own domain so they're indistinguishable from your site's own traffic.
                <a href="https://acceptrics.com/docs/relay" target="_blank" rel="noopener">Learn more</a>
            </p>

            <hr class="acpt-divider">

            <!-- ======================================================
                 Step: mode-select
                 ====================================================== -->
            <div class="acpt-relay-step" id="acpt-step-mode-select">
                <h3 class="acpt-step-heading">
                    <span class="acpt-step-num">1</span> How do you want to recover your analytics?
                </h3>
                <p style="font-size:13px;color:#666;margin:4px 0 0;">
                    Both modes route your Google tags through your own domain, recovering data
                    that ad blockers and browsers would otherwise block.
                </p>

                <div class="acpt-mode-cards">
                    <div class="acpt-mode-card" id="acpt-mode-card-dns" data-mode="dns">
                        <div class="acpt-mode-card-icon">&#x1F310;</div>
                        <div class="acpt-mode-card-title">DNS Subdomain</div>
                        <div class="acpt-mode-card-desc">
                            Your tags load from a subdomain you own
                            (e.g. <code>t.<?php echo esc_html($site_domain); ?></code>), backed by a
                            CDN. Requires two DNS records. Enables consent enforcement at the network layer.
                        </div>
                        <span class="acpt-mode-card-badge badge-cdn">CDN-backed &mdash; fastest</span>
                        <span class="acpt-mode-card-badge badge-paid" style="background:#fff3e0;color:#e65100;margin-left:4px;">$5/month</span>
                    </div>

                    <div class="acpt-mode-card" id="acpt-mode-card-path" data-mode="path">
                        <div class="acpt-mode-card-icon">&#x1F4BB;</div>
                        <div class="acpt-mode-card-title">Server Path</div>
                        <div class="acpt-mode-card-desc">
                            Tags load from a path on your own site
                            (e.g. <code><?php echo esc_html($site_domain); ?>/metrics</code>).
                            No DNS changes needed — activate immediately and start recovering analytics today.
                        </div>
                        <span class="acpt-mode-card-badge badge-server">Zero DNS changes</span>
                    </div>
                </div>

                <p id="acpt-dns-billing-note" style="display:none;margin-top:14px;font-size:12px;color:#e65100;background:#fff8f0;border:1px solid #ffe0b2;border-radius:6px;padding:10px 14px;">
                    <strong>DNS Subdomain Relay is $5/month.</strong>
                    Enable it first at <a href="https://acceptrics.com/account" target="_blank" rel="noopener" style="color:#e65100;">acceptrics.com/account</a>
                    under <strong>Add-ons &rarr; CNAME Relay</strong>, then return here to finish setup.
                </p>

                <button type="button" id="acpt-btn-choose-mode" class="acpt-btn-primary" disabled style="margin-top:20px;">
                    Continue
                </button>
                <p id="acpt-mode-error" class="acpt-error-msg"></p>
            </div>

            <!-- ======================================================
                 Step: token
                 ====================================================== -->
            <div class="acpt-relay-step" id="acpt-step-token">
                <button type="button" class="acpt-back-link" data-goto="mode-select">&#8592; Change relay type</button>
                <h3 class="acpt-step-heading">
                    <span class="acpt-step-num">2</span> Connect your Acceptrics account
                </h3>
                <p style="font-size:13px;color:#666;margin:4px 0 16px;">
                    Generate an API token in your
                    <a href="https://acceptrics.com/account" target="_blank" rel="noopener">Acceptrics dashboard</a>
                    under <strong>Account Settings → Developer API</strong>, then paste it here.
                </p>
                <div class="acpt-field-row">
                    <input
                        type="password"
                        id="acpt-token-input"
                        placeholder="Paste your API token"
                        autocomplete="off"
                        spellcheck="false"
                        style="max-width:420px;"
                    />
                    <button type="button" id="acpt-btn-connect-token" class="acpt-btn-primary" style="margin-top:0;">Connect</button>
                </div>
                <p id="acpt-token-error" class="acpt-error-msg"></p>
            </div>

            <!-- ======================================================
                 Step: configure
                 ====================================================== -->
            <div class="acpt-relay-step" id="acpt-step-configure">
                <button type="button" class="acpt-back-link" data-goto="token">&#8592; Back</button>
                <?php if ($token_connected) : ?>
                    <div style="margin-bottom:16px;">
                        <span class="acpt-token-connected">
                            &#x2713; API token connected
                            <button type="button" class="acpt-btn-disconnect">Disconnect</button>
                        </span>
                    </div>
                <?php endif; ?>

                <h3 class="acpt-step-heading">
                    <span class="acpt-step-num">3</span> Configure your subdomain
                </h3>
                <p style="font-size:13px;color:#666;margin:4px 0 16px;">
                    Enter the Google Tag ID to route through your subdomain. We'll provision a
                    CloudFront distribution and SSL certificate automatically.
                </p>

                <label class="acpt-field-label">Google Tag IDs <span style="font-weight:400;color:#999;">(at least one required)</span></label>
                <div id="acpt-provision-tags" class="acpt-tag-list"></div>
                <div class="acpt-add-tag-row" style="margin-bottom:4px;">
                    <input
                        type="text"
                        id="acpt-tag-id-input"
                        placeholder="G-XXXXXXXX or GTM-XXXXXXXX"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <button type="button" id="acpt-btn-add-tag" class="acpt-btn-secondary" style="margin-top:0;">Add tag</button>
                </div>
                <p style="font-size:11px;color:#999;margin:0 0 14px;">
                    Supports G-, GTM-, AW-, DC- prefixes. The first tag is the primary — the relay subdomain will be named after it.
                    Find your ID in Google Analytics under <strong>Admin &rarr; Data Streams</strong>, or your container ID at <a href="https://tagmanager.google.com" target="_blank" rel="noopener">tagmanager.google.com</a>.
                </p>

                <label class="acpt-field-label" for="acpt-subdomain-input">Relay subdomain</label>
                <div class="acpt-subdomain-row">
                    <input
                        type="text"
                        id="acpt-subdomain-input"
                        value="t"
                        autocomplete="off"
                        spellcheck="false"
                        maxlength="30"
                    />
                    <span class="acpt-subdomain-domain">.<?php echo esc_html($site_domain); ?></span>
                </div>
                <p style="font-size:12px;color:#888;margin:6px 0 0;">
                    Your tags will load from <code>t.<?php echo esc_html($site_domain); ?></code> (or your chosen subdomain).
                </p>

                <button type="button" id="acpt-btn-provision" class="acpt-btn-primary">Set up Subdomain</button>
                <p id="acpt-provision-error" class="acpt-error-msg"></p>
            </div>

            <!-- ======================================================
                 Step: configure-path
                 ====================================================== -->
            <div class="acpt-relay-step" id="acpt-step-configure-path">
                <button type="button" class="acpt-back-link" data-goto="mode-select">&#8592; Change relay type</button>
                <h3 class="acpt-step-heading">
                    <span class="acpt-step-num">2</span> Activate analytics recovery
                </h3>
                <p style="font-size:13px;color:#666;margin:4px 0 16px;">
                    No DNS changes or API token required. Choose a path on your site — tag requests
                    route through your WordPress server so ad blockers and browser restrictions
                    can't intercept them.
                </p>

                <label class="acpt-field-label">Recovery path</label>
                <div class="acpt-path-row">
                    <span class="acpt-path-origin"><?php echo esc_html(rtrim(get_site_url(), '/')); ?>/</span>
                    <input
                        type="text"
                        id="acpt-metrics-path-input"
                        value="<?php echo esc_attr(get_option('acceptrics_relay_metrics_path', 'metrics')); ?>"
                        autocomplete="off"
                        spellcheck="false"
                        maxlength="60"
                        placeholder="metrics"
                    />
                </div>
                <p style="font-size:12px;color:#888;margin:6px 0 16px;">
                    Your tags will load from
                    <code><?php echo esc_html(rtrim(get_site_url(), '/')); ?>/<span id="acpt-path-preview"><?php echo esc_html(get_option('acceptrics_relay_metrics_path', 'metrics')); ?></span></code>.
                    Use any URL-safe path that isn't already taken by a page or post.
                </p>

                <label class="acpt-field-label">Google Tag IDs <span style="font-weight:400;color:#999;">(at least one required)</span></label>
                <div id="acpt-path-provision-tags" class="acpt-tag-list"></div>
                <div class="acpt-add-tag-row" style="margin-bottom:4px;">
                    <input
                        type="text"
                        id="acpt-path-tag-id-input"
                        placeholder="G-XXXXXXXX or GTM-XXXXXXXX"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <button type="button" id="acpt-btn-path-add-tag" class="acpt-btn-secondary" style="margin-top:0;">Add tag</button>
                </div>
                <p style="font-size:11px;color:#999;margin:0 0 14px;">Supports G-, GTM-, AW-, DC- prefixes.
                    Find your ID in Google Analytics under <strong>Admin &rarr; Data Streams</strong>, or your container ID at <a href="https://tagmanager.google.com" target="_blank" rel="noopener">tagmanager.google.com</a>.</p>

                <label class="acpt-field-label" for="acpt-threshold-input" style="margin-top:18px;">
                    Fallback threshold
                    <span style="font-weight:400;color:#999;">(ms — switch to direct Google if median response time exceeds this)</span>
                </label>
                <div class="acpt-field-row" style="margin-top:4px;">
                    <input
                        type="number"
                        id="acpt-threshold-input"
                        value="800"
                        min="200"
                        max="5000"
                        step="100"
                        style="max-width:110px;font-family:monospace;"
                    />
                    <span style="font-size:13px;color:#888;">ms</span>
                </div>
                <p style="font-size:12px;color:#888;margin:4px 0 0;">
                    When your server is slow, analytics recovery automatically falls back to direct Google endpoints
                    for up to 60 seconds, then retries.
                </p>

                <hr class="acpt-divider" style="margin:20px 0 16px;">
                <div class="acpt-geo-notice">
                    <div>
                        <div style="font-size:13px;font-weight:600;color:#333;">
                            GeoIP Detect
                            <span style="font-weight:400;color:#888;font-size:12px;">&nbsp;(optional plugin)</span>
                        </div>
                        <div style="font-size:12px;color:#666;margin-top:3px;">
                            Forwards visitor country, region, and city to Google for accurate geo-targeting.
                            Without it, Google uses only the visitor's IP address.
                        </div>
                    </div>
                    <?php if ($geoip_active) : ?>
                        <span class="acpt-badge active">&#x2713; Installed</span>
                    <?php else : ?>
                        <a href="<?php echo esc_url($geoip_install_url); ?>" class="acpt-install-btn">Install Plugin</a>
                    <?php endif; ?>
                </div>

                <button type="button" id="acpt-btn-provision-path" class="acpt-btn-primary">Start Analytics Recovery</button>
                <p id="acpt-path-provision-error" class="acpt-error-msg"></p>
            </div>

            <!-- ======================================================
                 Step: dns
                 ====================================================== -->
            <div class="acpt-relay-step" id="acpt-step-dns">
                <?php if ($token_connected) : ?>
                    <div style="margin-bottom:16px;">
                        <span class="acpt-token-connected">
                            &#x2713; API token connected
                            <button type="button" class="acpt-btn-disconnect">Disconnect</button>
                        </span>
                    </div>
                <?php endif; ?>

                <h3 class="acpt-step-heading">
                    <span class="acpt-step-num">4</span> Add two DNS records
                </h3>
                <p style="font-size:13px;color:#666;margin:4px 0 16px;">
                    Add both records to your DNS for
                    <strong class="acpt-relay-hostname-display"><?php echo esc_html($relay_hostname); ?></strong>.
                    Analytics recovery activates automatically once the SSL certificate is issued (typically 5&ndash;30 minutes).
                </p>

                <table class="acpt-record-table">
                    <thead><tr><th>Type</th><th>Name</th><th>Value</th><th></th></tr></thead>
                    <tbody>
                        <!-- Gateway CNAME -->
                        <tr id="acpt-row-gw">
                            <td><span class="acpt-type-badge">CNAME</span></td>
                            <td><span class="acpt-record-val" id="acpt-record-gw-name"><?php echo esc_html($relay_subdomain); ?></span></td>
                            <td><span class="acpt-record-val" id="acpt-record-gw-val"><?php echo esc_html($relay_cf_domain); ?></span></td>
                            <td>
                                <button type="button" class="acpt-copy-btn" id="acpt-copy-gw-name"
                                    data-copy="<?php echo esc_attr($relay_subdomain); ?>">Copy name</button>
                                &nbsp;
                                <button type="button" class="acpt-copy-btn" id="acpt-copy-gw-val"
                                    data-copy="<?php echo esc_attr($relay_cf_domain); ?>">Copy value</button>
                            </td>
                        </tr>
                        <!-- Cert validation CNAME -->
                        <tr>
                            <td><span class="acpt-type-badge">CNAME</span></td>
                            <td><span class="acpt-record-val" id="acpt-record-cert-name"><?php echo esc_html($relay_cert_short); ?></span></td>
                            <td><span class="acpt-record-val" id="acpt-record-cert-val"><?php echo esc_html($relay_cert_val); ?></span></td>
                            <td>
                                <button type="button" class="acpt-copy-btn" id="acpt-copy-cert-name"
                                    data-copy="<?php echo esc_attr($relay_cert_short); ?>">Copy name</button>
                                &nbsp;
                                <button type="button" class="acpt-copy-btn" id="acpt-copy-cert-val"
                                    data-copy="<?php echo esc_attr($relay_cert_val); ?>">Copy value</button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p style="font-size:11px;color:#999;margin:6px 0 20px;">
                    Some providers want just the subdomain part (e.g. <code>_abc123.t</code>); others want the full name with your domain appended. Try the short form first.
                </p>

                <!-- Provider-specific instructions (populated by JS) -->
                <div id="acpt-dns-provider-steps"></div>

                <!-- Action buttons: open DNS panel + download zone file -->
                <div id="acpt-dns-actions" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:18px;">
                    <a id="acpt-btn-open-dns" href="#" target="_blank" rel="noopener"
                       class="acpt-btn-secondary" style="display:none;text-decoration:none;">
                        Open DNS panel &rarr;
                    </a>
                    <button type="button" id="acpt-btn-download-zone" class="acpt-btn-secondary" style="display:none;">
                        Download zone file
                    </button>
                    <span id="acpt-import-hint" style="display:none;font-size:11px;color:#888;">
                        Zone file import supported by your provider &mdash; use <strong>Import zone file</strong> in their DNS panel.
                    </span>
                </div>

                <button type="button" id="acpt-btn-records-added" class="acpt-btn-primary" style="margin-top:20px;">
                    I've added both records &mdash; check propagation
                </button>

                <!-- Propagation polling (hidden until button clicked) -->
                <div id="acpt-polling-section" class="acpt-poll-section" style="display:none;">
                    <hr class="acpt-divider">
                    <p style="font-size:13px;font-weight:600;color:#333;margin:0 0 10px;">
                        Checking propagation&hellip;
                    </p>

                    <div class="acpt-poll-row acpt-row-pending" id="acpt-status-cert">
                        <div class="acpt-spin"></div>
                        <span class="acpt-check" style="display:none;">&#x2713;</span>
                        <div class="acpt-poll-label">
                            <div>SSL Certificate</div>
                            <div class="acpt-poll-hint">ACM cert via DNS validation &mdash; typically 5&ndash;30 minutes</div>
                        </div>
                    </div>

                    <div class="acpt-poll-row acpt-row-pending" id="acpt-status-cname">
                        <div class="acpt-spin"></div>
                        <span class="acpt-check" style="display:none;">&#x2713;</span>
                        <div class="acpt-poll-label">
                            <div>Gateway CNAME</div>
                            <div class="acpt-poll-hint acpt-relay-hostname-display"><?php echo esc_html($relay_hostname); ?></div>
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;">
                        <button type="button" id="acpt-btn-check-now" class="acpt-btn-secondary" style="margin-top:0;">
                            Check now
                        </button>
                        <p class="acpt-poll-note" style="margin:0;" id="acpt-poll-note">
                            Checks automatically every 30 seconds. You can safely close this page.
                        </p>
                    </div>
                </div>

            </div>

            <!-- ======================================================
                 Step: active
                 ====================================================== -->
            <div class="acpt-relay-step" id="acpt-step-active">

                <!-- Status header -->
                <?php
                $active_relay_mode = get_option('acceptrics_relay_mode', 'dns');
                if ($active_relay_mode === 'path') {
                    $site_host_active     = parse_url(get_site_url(), PHP_URL_HOST);
                    $active_tag_paths_raw = (array) get_option('acceptrics_relay_tag_paths', []);
                    if (!empty($active_tag_paths_raw) && !empty($relay_tag_id) && !empty($active_tag_paths_raw[$relay_tag_id])) {
                        $active_relay_hostname = $site_host_active . '/' . $active_tag_paths_raw[$relay_tag_id];
                    } else {
                        $active_relay_hostname = $site_host_active . '/' . trim(get_option('acceptrics_relay_metrics_path', 'metrics'), '/');
                    }
                } else {
                    $active_relay_hostname = $relay_hostname;
                    $active_tag_paths_raw  = [];
                }
                ?>
                <div class="acpt-active-header">
                    <div style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;">
                        <span style="font-size:20px;">&#x2705;</span>
                        <div>
                            <strong style="font-size:15px;">Analytics Recovery active</strong>
                            <span class="acpt-mode-badge <?php echo ($active_relay_mode === 'path') ? 'path' : 'dns'; ?>" style="margin-left:8px;">
                                <?php echo ($active_relay_mode === 'path') ? 'Server Path' : 'DNS Subdomain'; ?>
                            </span>
                            <?php if ($relay_activated_display) : ?>
                                <span style="font-size:12px;color:#999;margin-left:8px;">since <?php echo esc_html($relay_activated_display); ?></span>
                            <?php endif; ?>
                            <div style="font-size:13px;color:#555;margin-top:2px;">
                                Analytics recovering via
                                <code class="acpt-relay-hostname-display" style="background:#f0f0f0;padding:1px 6px;border-radius:3px;"><?php echo esc_html($active_relay_hostname); ?></code>
                            </div>
                            <?php if ($active_relay_mode === 'path') : ?>
                                <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                    <span id="acpt-health-badge" class="acpt-health-badge unknown">
                                        <span class="acpt-health-dot"></span>
                                        <span id="acpt-health-label">Checking&hellip;</span>
                                    </span>
                                    <span id="acpt-health-stats" style="font-size:11px;color:#999;"></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Relay URL / Paths -->
                <div style="background:#f9f9f9;border:1px solid #eeeeee;border-radius:6px;padding:12px 14px;margin:18px 0 12px;">
                    <?php if ($active_relay_mode === 'path' && !empty($active_tag_paths_raw) && count($active_tag_paths_raw) > 1) : ?>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#999;margin-bottom:8px;">Relay Paths</div>
                    <?php foreach ($active_tag_paths_raw as $_tid => $_path) : ?>
                    <div style="font-family:monospace;font-size:13px;color:#333;word-break:break-all;margin-bottom:4px;">
                        <span style="color:#999;font-size:11px;"><?php echo esc_html($_tid); ?></span>
                        &rarr; https://<?php echo esc_html($site_host_active . '/' . $_path); ?>
                    </div>
                    <?php endforeach; ?>
                    <?php else : ?>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#999;margin-bottom:4px;">Relay URL</div>
                    <div style="font-family:monospace;font-size:13px;color:#333;word-break:break-all;" id="acpt-detail-hostname">
                        <?php echo esc_html('https://' . $active_relay_hostname); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tag management -->
                <div style="margin-bottom:18px;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#999;margin-bottom:6px;">Tags</div>
                    <div id="acpt-active-tags" class="acpt-tag-list">
                        <?php foreach ($relay_tag_ids as $tid) : ?>
                            <span class="acpt-tag-pill<?php echo ($tid === $relay_tag_id) ? ' acpt-tag-primary' : ''; ?>"
                                  data-tag="<?php echo esc_attr($tid); ?>">
                                <?php echo esc_html($tid); ?>
                                <?php if ($tid === $relay_tag_id) : ?>
                                    <span style="font-size:10px;opacity:0.6;margin-left:2px;">primary</span>
                                <?php endif; ?>
                                <button type="button" class="acpt-tag-pill-remove" data-tag="<?php echo esc_attr($tid); ?>" title="Remove">&times;</button>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="acpt-add-tag-row">
                        <input type="text" id="acpt-new-tag-input" placeholder="Add another tag (AW-, G-, GTM-…)" autocomplete="off" spellcheck="false" />
                        <button type="button" id="acpt-btn-add-active-tag" class="acpt-btn-secondary" style="margin-top:0;">Add tag</button>
                    </div>
                    <p id="acpt-tags-error" class="acpt-error-msg" style="margin-top:8px;"></p>
                </div>

                <!-- GeoIP Detect — optional enhancement, shown after core status -->
                <?php if ($active_relay_mode === 'path' && !$geoip_active) : ?>
                <div class="acpt-geo-notice" style="margin-bottom:18px;">
                    <div>
                        <div style="font-size:13px;font-weight:600;color:#333;">GeoIP Detect <span style="font-weight:400;font-size:12px;color:#888;">(optional)</span></div>
                        <div style="font-size:12px;color:#666;margin-top:2px;">Forwards visitor location to Google for accurate geo-targeting.</div>
                    </div>
                    <a href="<?php echo esc_url($geoip_install_url); ?>" class="acpt-install-btn">Install Plugin</a>
                </div>
                <?php endif; ?>

                <!-- Routing strategy selector (path mode only) -->
                <?php if ($active_relay_mode === 'path') :
                    $relay_strategy = get_option('acceptrics_relay_strategy', 'adaptive');
                ?>
                <div style="margin-bottom:20px;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#999;margin-bottom:8px;">Routing Strategy</div>
                    <div style="display:flex;flex-direction:column;gap:8px;" id="acpt-strategy-options">
                        <?php
                        $strategies = [
                            'adaptive'      => ['label' => 'Adaptive', 'desc' => 'Browser probes Google directly; uses relay only when blocked. Reduces server load.', 'recommended' => true, 'warning' => false],
                            'always_relay'  => ['label' => 'Always first-party relay', 'desc' => 'All tag traffic routes through your server. Maximum recovery, higher server load.', 'recommended' => false, 'warning' => false],
                            'always_direct' => ['label' => 'Always direct Google', 'desc' => 'Standard Google tags — relay disabled. Visitors with ad blockers will not be recovered.', 'recommended' => false, 'warning' => true],
                        ];
                        foreach ($strategies as $key => $opt) :
                            $is_selected = ($relay_strategy === $key);
                            $is_warning  = $opt['warning'];
                            $border      = $is_selected ? ($is_warning ? '#ef5350' : '#4CAF50') : ($is_warning ? '#ffcdd2' : '#e0e0e0');
                            $bg          = $is_selected ? ($is_warning ? '#ffebee' : '#f1fbf1') : '#fff';
                        ?>
                        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:10px 12px;border:1.5px solid <?php echo $border; ?>;border-radius:6px;background:<?php echo $bg; ?>;" class="acpt-strategy-label" data-strategy="<?php echo esc_attr($key); ?>">
                            <input type="radio" name="acpt_relay_strategy" value="<?php echo esc_attr($key); ?>" <?php checked($relay_strategy, $key); ?> style="margin-top:2px;flex-shrink:0;" />
                            <div>
                                <div style="font-size:13px;font-weight:600;color:<?php echo $is_warning ? '#c62828' : '#333'; ?>;">
                                    <?php echo esc_html($opt['label']); ?>
                                    <?php if ($opt['recommended']) : ?>
                                        <span style="font-size:11px;font-weight:600;color:#2e7d32;background:#e8f5e9;padding:1px 7px;border-radius:10px;margin-left:6px;">Recommended</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:12px;color:<?php echo $is_warning ? '#c62828' : '#888'; ?>;margin-top:2px;"><?php echo esc_html($opt['desc']); ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="acpt-btn-save-strategy" class="acpt-btn-secondary" style="margin-top:10px;">Save strategy</button>
                    <span id="acpt-strategy-result" style="font-size:12px;margin-left:8px;display:none;"></span>
                </div>
                <?php endif; ?>

                <!-- Live connection test -->
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
                    <button type="button" id="acpt-btn-test-relay" class="acpt-btn-secondary" style="margin-top:0;">
                        Test connection
                    </button>
                    <span id="acpt-test-result" style="font-size:13px;display:none;"></span>
                </div>

                <hr class="acpt-divider">

                <!-- Deployment status -->
                <div style="background:#f1fbf1;border:1px solid #a5d6a7;border-radius:6px;padding:14px 16px;margin-bottom:20px;">
                    <div style="font-size:14px;font-weight:600;color:#1b5e20;margin-bottom:4px;">
                        &#x2705; Your tag<?php echo count($relay_tag_ids) > 1 ? 's are' : ' is'; ?> deployed
                    </div>
                    <p style="font-size:13px;color:#2e7d32;margin:0;">
                        <?php echo esc_html(implode(', ', $relay_tag_ids)); ?> <?php echo count($relay_tag_ids) > 1 ? 'are' : 'is'; ?> routing first-party through your server.
                        Tag requests from your visitors bypass ad blockers and browser restrictions automatically &mdash; no snippet changes needed.
                    </p>
                </div>

                <hr class="acpt-divider">
                <button type="button" class="acpt-btn-disconnect" style="font-size:13px;color:#999;">
                    Disconnect
                </button>

            </div>

            <!-- ======================================================
                 Step: failed
                 ====================================================== -->
            <div class="acpt-relay-step" id="acpt-step-failed">
                <p style="color:#c62828;font-size:14px;font-weight:600;">&#x26A0; Setup failed</p>
                <p style="font-size:13px;color:#666;">
                    Analytics recovery could not be activated within 24 hours. This usually means one or both
                    DNS records were not added correctly, or propagation timed out.
                </p>
                <button type="button" id="acpt-btn-retry" class="acpt-btn-primary">Try again</button>
                <button type="button" class="acpt-btn-disconnect acpt-btn-secondary" style="margin-left:8px;">
                    Disconnect
                </button>
            </div>

        </div><!-- /acpt-relay-card -->

        <?php endif; // end settings tab ?>

        <?php if ($active_tab === 'report') : ?>

        <!-- ============================================================
             Scope note — this tab is about analytics recovery, not the banner
             ============================================================ -->
        <?php $acpt_relay_is_active = (get_option('acceptrics_relay_status') === 'active'); ?>
        <div class="acpt-card" style="border-left:3px solid #B469B5;">
            <p class="acpt-card-desc" style="margin:0;">
                This report is about <strong>analytics recovery (Tag Relay)</strong>: how many of your
                visitors block third-party analytics, and what routing tags through your own domain
                recovers. Your <strong>consent banner is not affected by ad blockers</strong> and isn't
                measured here &mdash; it works the same whether or not you use Tag Relay.
                <?php if ($acpt_relay_is_active) : ?>
                    Tag Relay is <strong>active</strong> on this site, so the numbers below include
                    the traffic it recovers.
                <?php else : ?>
                    Tag Relay is <strong>not active</strong> on this site &mdash; use blocker detection
                    below to see how much analytics data you're losing and whether
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=acceptrics-consent-banner&tab=settings')); ?>">Analytics Recovery</a>
                    is worth enabling.
                <?php endif; ?>
            </p>
        </div>

        <!-- ============================================================
             Blocker detection toggle (saved via report tab form)
             ============================================================ -->
        <div class="acpt-card">
            <h2>Blocker Detection</h2>
            <p class="acpt-card-desc">
                On 10% of page loads, fires a test request to <code>googletagmanager.com</code> to check
                whether your visitors can reach Google's analytics servers directly. If the request fails,
                that visitor likely has an ad blocker or browser restriction blocking third-party requests.
                Only the aggregate pass/fail count is stored — no visitor data is retained.
            </p>
            <form method="post" action="options.php">
                <?php settings_fields('acceptrics_settings_group'); ?>
                <div class="acpt-toggle-row">
                    <input type="checkbox" name="acceptrics_blocker_detect_enabled" id="acpt_detect_report_enabled"
                           value="1" <?php checked(1, $detect_enabled); ?> style="margin-top:2px;" />
                    <div>
                        <label for="acpt_detect_report_enabled">Enable blocker detection</label>
                        <div class="acpt-toggle-desc">Samples 10% of page loads. Adds ~1ms on sampled visits.</div>
                    </div>
                </div>
                <?php submit_button('Save', 'primary', 'submit', true, ['style' => 'margin-top:14px;']); ?>
            </form>
        </div>

        <!-- ============================================================
             Report: uplift estimate + daily stats
             ============================================================ -->
        <?php if ($det_total_sampled > 0) : ?>

        <div class="acpt-card">
            <h2>Analytics Uplift Estimate</h2>

            <?php if ($det_blocker_pct !== null) : ?>
            <div class="acpt-detect-summary">
                <div class="acpt-detect-big-num"><?php echo esc_html($det_blocker_pct); ?>%</div>
                <div class="acpt-detect-big-label">of your visitors have an ad blocker or browser restriction</div>
                <div class="acpt-detect-big-desc">
                    Based on <?php echo esc_html(number_format($det_total_sampled * 10)); ?> estimated page loads
                    (<?php echo esc_html(number_format($det_total_sampled)); ?> sampled).
                    <?php if ($det_avg_b !== null && get_option('acceptrics_relay_status') === 'active') : ?>
                        With Analytics Recovery active, approximately
                        <strong><?php echo esc_html(number_format($det_avg_b)); ?> events/day</strong>
                        are being recovered that would otherwise be lost.
                    <?php elseif (get_option('acceptrics_relay_status') !== 'active') : ?>
                        Enable Analytics Recovery in <a href="<?php echo esc_url(admin_url('options-general.php?page=acceptrics-consent-banner&tab=settings')); ?>">Settings</a>
                        to start capturing these events.
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <table class="acpt-detect-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Est. visitors</th>
                        <th>Est. blocked</th>
                        <th>Blocker rate</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($detect_recent as $det_date => $det_counts) :
                    $det_b   = intval($det_counts['blocked']);
                    $det_u   = intval($det_counts['unblocked']);
                    $det_n   = $det_b + $det_u;
                    $det_pct = $det_n > 0 ? round(($det_b / $det_n) * 100) . '%' : '&mdash;';
                ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('M j', strtotime($det_date))); ?></td>
                        <td><?php echo esc_html(number_format($det_n * 10)); ?></td>
                        <td><?php echo esc_html(number_format($det_b * 10)); ?></td>
                        <td><?php echo $det_pct; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:11px;color:#999;margin-top:8px;">
                Estimates extrapolated &times;10 from a 10% sample. Actual numbers may vary by &plusmn;15%.
            </p>
        </div>

        <?php else : ?>

        <div class="acpt-card">
            <div class="acpt-detect-empty">
                <div class="acpt-detect-empty-icon">&#128202;</div>
                <div class="acpt-detect-empty-title">No data yet</div>
                <div style="font-size:13px;">
                    <?php if ($detect_enabled) : ?>
                        Blocker detection is on. Data will appear here within a few hours of traffic.
                    <?php else : ?>
                        Enable blocker detection above to start collecting data.
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php endif; // end has data ?>

        <!-- ============================================================
             Adaptive routing stats
             ============================================================ -->
        <?php
        $adaptive_stats  = (array) get_option('acceptrics_adaptive_daily', []);
        krsort($adaptive_stats);
        $adaptive_recent = array_slice($adaptive_stats, 0, 7, true);
        $adp_total_direct = 0;
        $adp_total_relay  = 0;
        $adp_total_blocked = 0;
        $adp_total_ok      = 0;
        $adp_total_degraded = 0;
        foreach ($adaptive_recent as $_d) {
            $adp_total_direct   += intval($_d['direct'] ?? 0);
            $adp_total_relay    += intval($_d['relay'] ?? 0);
            $adp_total_blocked  += intval($_d['direct_blocked'] ?? 0);
            $adp_total_ok       += intval($_d['direct_ok'] ?? 0);
            $adp_total_degraded += intval($_d['server_degraded'] ?? 0);
        }
        $adp_total = $adp_total_direct + $adp_total_relay;
        ?>
        <?php if ($adp_total > 0) : ?>
        <div class="acpt-card">
            <h2>Adaptive Routing Stats</h2>
            <p class="acpt-card-desc">
                Sampled route decisions from the last 7 days (<?php echo esc_html(round((float) get_option('acceptrics_adaptive_stats_sample_rate', 0.10) * 100)); ?>% sample rate).
                "Direct routed" means the browser could reach Google without the relay &mdash; no server load incurred.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px;">
                <?php
                $stat_tiles = [
                    ['Direct routed',   'Relay not needed',  $adp_total_direct,   '#e8f5e9', '#2e7d32'],
                    ['Relay routed',    'Relay active',      $adp_total_relay,    '#fff8e1', '#e65100'],
                    ['Server degraded', 'Circuit breaker',   $adp_total_degraded, '#f5f5f5', '#757575'],
                ];
                foreach ($stat_tiles as $tile) :
                    list($label, $sublabel, $val, $bg, $fg) = $tile;
                ?>
                <div style="background:<?php echo $bg; ?>;border-radius:6px;padding:12px 14px;text-align:center;">
                    <div style="font-size:26px;font-weight:700;color:<?php echo $fg; ?>;"><?php echo esc_html(number_format($val)); ?></div>
                    <div style="font-size:12px;font-weight:600;color:<?php echo $fg; ?>;margin-top:4px;"><?php echo esc_html($label); ?></div>
                    <div style="font-size:11px;color:<?php echo $fg; ?>;opacity:0.75;margin-top:2px;"><?php echo esc_html($sublabel); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <table class="acpt-detect-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th style="color:#2e7d32;">Direct</th>
                        <th style="color:#e65100;">Relay</th>
                        <th>Relay rate</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($adaptive_recent as $adp_date => $adp_row) :
                    $d  = intval($adp_row['direct'] ?? 0);
                    $r  = intval($adp_row['relay'] ?? 0);
                    $n  = $d + $r;
                    $pct = $n > 0 ? round(($r / $n) * 100) . '%' : '&mdash;';
                ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('M j', strtotime($adp_date))); ?></td>
                        <td style="color:#2e7d32;"><?php echo esc_html(number_format($d)); ?></td>
                        <td style="color:#e65100;"><?php echo esc_html(number_format($r)); ?></td>
                        <td><?php echo $pct; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:11px;color:#999;margin-top:8px;">
                Sampled counts &mdash; actual totals are approximately &times;<?php echo esc_html(round(1 / max(0.01, (float) get_option('acceptrics_adaptive_stats_sample_rate', 0.10)))); ?> larger.
                Relay rate = share of loads where Google was unreachable and the relay stepped in.
            </p>
        </div>
        <?php endif; ?>

        <?php endif; // end report tab ?>

    </div><!-- /acceptrics-admin -->
    <?php
}
