<?php
if (!defined('ABSPATH')) {
    exit;
}

// Base URL for Acceptrics API — filterable so staging/local envs can override.
if (!defined('ACCEPTRICS_API_URL')) {
    define('ACCEPTRICS_API_URL', 'https://siyfnlsefe.execute-api.us-west-2.amazonaws.com/prod');
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Make an authenticated request to the Acceptrics API.
 * Returns decoded JSON array on success, WP_Error on failure.
 */
function acceptrics_api_request($method, $path, $body = null) {
    $token = get_option('acceptrics_api_token', '');
    if (empty($token)) {
        return new WP_Error('no_token', 'No API token configured.');
    }

    $args = [
        'method'  => strtoupper($method),
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ],
        'timeout' => 20,
    ];

    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request(ACCEPTRICS_API_URL . $path, $args);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    if ($code >= 400) {
        $msg = (is_array($data) && isset($data['error'])) ? $data['error'] : 'Acceptrics API error (' . $code . ').';
        return new WP_Error('api_error', $msg, ['status' => $code]);
    }

    return $data;
}

/**
 * Extract clean root domain from the site URL (no scheme, no www, no path).
 */
function acceptrics_get_site_domain() {
    $url = get_site_url();
    $url = preg_replace('/^https?:\/\//i', '', $url);
    $url = explode('/', $url)[0];
    $url = preg_replace('/^www\./i', '', $url);
    return strtolower($url);
}

/**
 * Strip the root domain and trailing dot from a fully-qualified CNAME name.
 * "_abc123.t.example.com." → "_abc123.t"
 */
function acceptrics_strip_cname_domain($full_name, $root_domain) {
    $name = rtrim($full_name, '.');
    $suffix = '.' . rtrim($root_domain, '.');
    if (substr($name, -strlen($suffix)) === $suffix) {
        return substr($name, 0, -strlen($suffix));
    }
    return $name;
}

// ---------------------------------------------------------------------------
// DNS Provider Detection
// ---------------------------------------------------------------------------

/**
 * Look up NS records for $domain and identify the DNS provider.
 * Returns [ 'provider' => string, 'ns' => string[] ]
 */
function acceptrics_detect_dns_provider($domain) {
    $records = @dns_get_record($domain, DNS_NS);
    if (empty($records)) {
        return ['provider' => 'generic', 'ns' => []];
    }

    $ns_list = array_map(function($r) { return strtolower($r['target'] ?? ''); }, $records);
    $ns_str  = implode(' ', $ns_list);

    if (strpos($ns_str, 'cloudflare.com') !== false)              $provider = 'cloudflare';
    elseif (strpos($ns_str, 'domaincontrol.com') !== false)       $provider = 'godaddy';
    elseif (strpos($ns_str, 'registrar-servers.com') !== false)   $provider = 'namecheap';
    elseif (strpos($ns_str, 'awsdns') !== false)                  $provider = 'route53';
    elseif (strpos($ns_str, 'squarespace.com') !== false
         || strpos($ns_str, 'googledomains.com') !== false)       $provider = 'squarespace';
    elseif (strpos($ns_str, 'bluehost.com') !== false)            $provider = 'bluehost';
    elseif (strpos($ns_str, 'siteground') !== false)              $provider = 'siteground';
    elseif (strpos($ns_str, 'hostinger') !== false)               $provider = 'hostinger';
    elseif (strpos($ns_str, 'dreamhost.com') !== false)           $provider = 'dreamhost';
    elseif (strpos($ns_str, 'name.com') !== false)                $provider = 'name_com';
    else                                                          $provider = 'generic';

    return ['provider' => $provider, 'ns' => $ns_list];
}

/**
 * Return label + step-by-step instructions for a given provider.
 * Steps already contain escaped HTML so they can be rendered as innerHTML.
 *
 * @param string $provider     Provider key.
 * @param string $subdomain    e.g. "t"
 * @param string $cf_domain    CloudFront domain, e.g. "d123abc.cloudfront.net"
 * @param string $acme_name    Short cert CNAME name, e.g. "_abc123.t"
 * @param string $acme_value   Cert CNAME value, e.g. "xyz.acm-validations.aws"
 */
function acceptrics_get_provider_instructions($provider, $subdomain, $cf_domain, $acme_name, $acme_value) {
    $s  = esc_html($subdomain);
    // Use a sentinel for the gateway target — JS replaces it with the live CF domain
    // once the distribution is created. This prevents storing an empty value in the DB.
    $cf = '__GW_TARGET__';
    $an = esc_html($acme_name);
    $av = esc_html($acme_value);

    $map = [

        'cloudflare' => [
            'label' => 'Cloudflare',
            'url'   => 'https://dash.cloudflare.com',
            'steps' => [
                'Log in to <a href="https://dash.cloudflare.com" target="_blank" rel="noopener">Cloudflare</a> and select your domain.',
                'Go to <strong>DNS → Records</strong> and click <strong>Add record</strong>.',
                '<strong>Certificate validation record</strong> — Type: <code>CNAME</code> &nbsp;·&nbsp; Name: <code>' . $an . '</code> &nbsp;·&nbsp; Target: <code>' . $av . '</code> &nbsp;·&nbsp; Proxy status: <strong>DNS only</strong> (grey cloud).',
                'Click <strong>Save</strong>. Wait for the SSL certificate to be issued (5–30 min), then return here to add the gateway record.',
                '<strong>Gateway record</strong> — Type: <code>CNAME</code> &nbsp;·&nbsp; Name: <code>' . $s . '</code> &nbsp;·&nbsp; Target: <code>' . $cf . '</code> &nbsp;·&nbsp; Proxy status: <strong>DNS only</strong> (grey cloud — not orange). Proxying will break tag routing.',
                'Click <strong>Save</strong>.',
            ],
        ],

        'godaddy' => [
            'label' => 'GoDaddy',
            'url'   => 'https://dcc.godaddy.com/manage/dns',
            'steps' => [
                'Sign in to your <a href="https://dcc.godaddy.com/manage/dns" target="_blank" rel="noopener">GoDaddy Domain Portfolio</a>, select your domain, then click <strong>DNS</strong>.',
                'Click <strong>Add New Record</strong> and select type <strong>CNAME</strong>.',
                '<strong>Certificate validation record</strong> — Name: <code>' . $an . '</code> &nbsp;·&nbsp; Value: <code>' . $av . '</code> &nbsp;·&nbsp; TTL: 1 Hour.',
                'Click <strong>Save</strong>. Wait for the SSL certificate to be issued (5–30 min), then continue.',
                'Click <strong>Add New Record</strong> again.',
                '<strong>Gateway record</strong> — Name: <code>' . $s . '</code> &nbsp;·&nbsp; Value: <code>' . $cf . '</code> &nbsp;·&nbsp; TTL: 1 Hour.',
                'Click <strong>Save</strong>. If your domain has Domain Protection enabled you\'ll need to verify with a 2-step code.',
            ],
        ],

        'namecheap' => [
            'label' => 'Namecheap',
            'url'   => 'https://ap.www.namecheap.com/domains/list/',
            'steps' => [
                'Sign in to Namecheap, go to <a href="https://ap.www.namecheap.com/domains/list/" target="_blank" rel="noopener">Domain List</a>, and click <strong>Manage</strong> next to your domain.',
                'Open the <strong>Advanced DNS</strong> tab and click <strong>Add New Record</strong>.',
                '<strong>Certificate validation record</strong> — Type: <code>CNAME Record</code> &nbsp;·&nbsp; Host: <code>' . $an . '</code> &nbsp;·&nbsp; Value: <code>' . $av . '</code> &nbsp;·&nbsp; TTL: Automatic. Click the green checkmark to save.',
                'Wait for the SSL certificate to be issued (5–30 min), then click <strong>Add New Record</strong> again.',
                '<strong>Gateway record</strong> — Type: <code>CNAME Record</code> &nbsp;·&nbsp; Host: <code>' . $s . '</code> &nbsp;·&nbsp; Value: <code>' . $cf . '</code> &nbsp;·&nbsp; TTL: Automatic.',
                'Click the green checkmark to save. Note: Namecheap auto-appends your domain — enter just the subdomain part, not the full hostname.',
            ],
        ],

        'route53' => [
            'label' => 'AWS Route 53',
            'url'   => 'https://console.aws.amazon.com/route53/',
            'steps' => [
                'Sign in to the <a href="https://console.aws.amazon.com/route53/" target="_blank" rel="noopener">AWS Console</a> and open Route 53 → <strong>Hosted zones</strong>.',
                'Click the hosted zone for your domain, then click <strong>Create record</strong>.',
                '<strong>Certificate validation record</strong> — Record name: <code>' . $an . '</code> &nbsp;·&nbsp; Record type: <code>CNAME</code> &nbsp;·&nbsp; Value: <code>' . $av . '</code> &nbsp;·&nbsp; TTL: 300. Click <strong>Create records</strong>.',
                'Wait for the SSL certificate to be issued (5–30 min — check ACM in us-east-1), then click <strong>Create record</strong> again.',
                '<strong>Gateway record</strong> — Record name: <code>' . $s . '</code> &nbsp;·&nbsp; Record type: <code>CNAME</code> &nbsp;·&nbsp; Value: <code>' . $cf . '</code> &nbsp;·&nbsp; TTL: 300.',
                'Click <strong>Create records</strong>. Changes propagate to all Route 53 name servers within ~60 seconds.',
            ],
        ],

        'squarespace' => [
            'label' => 'Squarespace Domains',
            'url'   => 'https://account.squarespace.com/domains',
            'steps' => [
                'Open your <a href="https://account.squarespace.com/domains" target="_blank" rel="noopener">Squarespace Domains Dashboard</a> and click your domain.',
                'Click <strong>DNS</strong>, then <strong>DNS Settings</strong>, then scroll to <strong>Custom Records</strong> and click <strong>Add record</strong>. Enter your password when prompted.',
                '<strong>Certificate validation record</strong> — Type: <code>CNAME</code> &nbsp;·&nbsp; Name: <code>' . $an . '</code> &nbsp;·&nbsp; Data: <code>' . $av . '</code>. Click <strong>Save</strong>.',
                'Wait for the SSL certificate to be issued (5–30 min), then click <strong>Add record</strong> again.',
                '<strong>Gateway record</strong> — Type: <code>CNAME</code> &nbsp;·&nbsp; Name: <code>' . $s . '</code> &nbsp;·&nbsp; Data: <code>' . $cf . '</code>.',
                'Click <strong>Save</strong>. Note: CNAME values must be a plain hostname — no trailing slash or path.',
            ],
        ],

        'bluehost' => [
            'label' => 'Bluehost',
            'url'   => 'https://my.bluehost.com',
            'steps' => [
                'Log in to <a href="https://my.bluehost.com" target="_blank" rel="noopener">Bluehost</a> and go to <strong>Domains → My Domains</strong>.',
                'Click your domain, open the <strong>DNS</strong> tab, scroll to <strong>CNAME (Alias)</strong> and click <strong>Add Record</strong>.',
                '<strong>Certificate validation record</strong> — Host Record: <code>' . $an . '</code> &nbsp;·&nbsp; Points To: <code>' . $av . '</code> &nbsp;·&nbsp; TTL: 14400. Click <strong>Add Record</strong>.',
                'Wait for the SSL certificate to be issued (5–30 min), then click <strong>Add Record</strong> again.',
                '<strong>Gateway record</strong> — Host Record: <code>' . $s . '</code> &nbsp;·&nbsp; Points To: <code>' . $cf . '</code> &nbsp;·&nbsp; TTL: 14400.',
                'Click <strong>Save All Changes</strong>.',
            ],
        ],

        'siteground' => [
            'label' => 'SiteGround',
            'url'   => 'https://my.siteground.com',
            'steps' => [
                'Log in to SiteGround and open <strong>Site Tools</strong> for your site.',
                'Go to <strong>Domain → DNS Zone Editor</strong> and click the <strong>CNAME</strong> tab.',
                '<strong>Certificate validation record</strong> — Name: <code>' . $an . '</code> &nbsp;·&nbsp; Points to: <code>' . $av . '</code> &nbsp;·&nbsp; TTL: 1 Hour. Click <strong>Create</strong>.',
                'Wait for the SSL certificate to be issued (5–30 min), then click the <strong>CNAME</strong> tab again.',
                '<strong>Gateway record</strong> — Name: <code>' . $s . '</code> &nbsp;·&nbsp; Points to: <code>' . $cf . '</code> &nbsp;·&nbsp; TTL: 1 Hour.',
                'Click <strong>Create</strong>. Note: if a record already exists for the same subdomain you must delete it first.',
            ],
        ],

        'hostinger' => [
            'label' => 'Hostinger',
            'url'   => 'https://hpanel.hostinger.com',
            'steps' => [
                'Log in to <a href="https://hpanel.hostinger.com" target="_blank" rel="noopener">Hostinger hPanel</a>, go to <strong>Domains</strong> and click <strong>Manage</strong> next to your domain.',
                'Select <strong>DNS / Nameservers</strong> from the sidebar, then click <strong>Add Record</strong>.',
                '<strong>Certificate validation record</strong> — Type: <code>CNAME</code> &nbsp;·&nbsp; Name: <code>' . $an . '</code> &nbsp;·&nbsp; Points to: <code>' . $av . '</code> &nbsp;·&nbsp; TTL: 14400. Click <strong>Add Record</strong>.',
                'Wait for the SSL certificate to be issued (5–30 min), then click <strong>Add Record</strong> again.',
                '<strong>Gateway record</strong> — Type: <code>CNAME</code> &nbsp;·&nbsp; Name: <code>' . $s . '</code> &nbsp;·&nbsp; Points to: <code>' . $cf . '</code> &nbsp;·&nbsp; TTL: 14400.',
                'Click <strong>Add Record</strong>. DNS changes can take up to 24 hours to propagate fully.',
            ],
        ],

        'dreamhost' => [
            'label' => 'DreamHost',
            'url'   => 'https://panel.dreamhost.com',
            'steps' => [
                'Log in to the <a href="https://panel.dreamhost.com" target="_blank" rel="noopener">DreamHost Panel</a> and go to <strong>Manage Websites</strong>.',
                'Click the 3-dots menu next to your domain and select <strong>DNS Settings</strong>, then click <strong>Add Record</strong>.',
                '<strong>Certificate validation record</strong> — Hover over <strong>CNAME Record</strong> and click <strong>ADD</strong>. Host: <code>' . $an . '</code> &nbsp;·&nbsp; Points to: <code>' . $av . '</code>. Click <strong>Add Record</strong>.',
                'Wait for the SSL certificate to be issued (5–30 min), then click <strong>Add Record</strong> again.',
                '<strong>Gateway record</strong> — Type: <strong>CNAME</strong>. Host: <code>' . $s . '</code> &nbsp;·&nbsp; Points to: <code>' . $cf . '</code>.',
                'Click <strong>Add Record</strong>. Do not include a trailing dot — DreamHost adds it automatically.',
            ],
        ],

        'name_com' => [
            'label' => 'Name.com',
            'url'   => 'https://www.name.com/account/domain',
            'steps' => [
                'Log in to <a href="https://www.name.com/account/domain" target="_blank" rel="noopener">Name.com</a>, click <strong>My Domains</strong>, then click your domain name.',
                'Click <strong>Manage DNS Records</strong>, select type <strong>CNAME</strong>.',
                '<strong>Certificate validation record</strong> — Host: <code>' . $an . '</code> &nbsp;·&nbsp; Answer: <code>' . $av . '</code> &nbsp;·&nbsp; TTL: 300. Click <strong>Save</strong>.',
                'Wait for the SSL certificate to be issued (5–30 min), then add the second record.',
                '<strong>Gateway record</strong> — Type: <code>CNAME</code> &nbsp;·&nbsp; Host: <code>' . $s . '</code> &nbsp;·&nbsp; Answer: <code>' . $cf . '</code> &nbsp;·&nbsp; TTL: 300.',
                'Click <strong>Save</strong>.',
            ],
        ],

        'generic' => [
            'label' => 'Unknown provider',
            'url'   => '',
            'steps' => [
                'Log in to your DNS provider and open the DNS management page for your domain.',
                '<strong>Certificate validation record</strong> — Type: <code>CNAME</code> &nbsp;·&nbsp; Name/Host: <code>' . $an . '</code> &nbsp;·&nbsp; Value/Target: <code>' . $av . '</code> &nbsp;·&nbsp; TTL: 3600. Save the record.',
                'Wait for the SSL certificate to be issued (5–30 min), then add the second record.',
                '<strong>Gateway record</strong> — Type: <code>CNAME</code> &nbsp;·&nbsp; Name/Host: <code>' . $s . '</code> &nbsp;·&nbsp; Value/Target: <code>' . $cf . '</code> &nbsp;·&nbsp; TTL: 3600.',
                'Save your changes. DNS propagation typically takes a few minutes but can take up to 48 hours.',
            ],
        ],
    ];

    return $map[$provider] ?? $map['generic'];
}

// ---------------------------------------------------------------------------
// Deep link helpers — direct URL into each provider's DNS management page
// ---------------------------------------------------------------------------

/**
 * Return the deepest useful URL into a provider's DNS panel for $site_domain.
 * Where we can include the domain in the URL we do; otherwise generic panel root.
 */
function acceptrics_get_provider_deeplink($provider, $site_domain) {
    $d = rawurlencode($site_domain);
    switch ($provider) {
        case 'godaddy':     return 'https://dcc.godaddy.com/manage/' . $d . '/dns';
        case 'namecheap':   return 'https://ap.www.namecheap.com/domains/domaindetails/' . $d . '#advancedDns';
        case 'squarespace': return 'https://account.squarespace.com/domains/' . $d . '/dns';
        case 'hostinger':   return 'https://hpanel.hostinger.com/domain/' . $d . '/dns';
        case 'name_com':    return 'https://www.name.com/account/domain/' . $d . '/dns-records';
        case 'dreamhost':   return 'https://panel.dreamhost.com/index.cgi?tree=domain.dns';
        case 'cloudflare':  return 'https://dash.cloudflare.com'; // needs account+zone IDs we don't have
        case 'route53':     return 'https://us-east-1.console.aws.amazon.com/route53/v2/hostedzones'; // needs zone ID
        case 'siteground':  return 'https://my.siteground.com';
        case 'bluehost':    return 'https://my.bluehost.com';
        default:            return '';
    }
}

/**
 * Returns true for providers that accept a BIND zone file import.
 * Confirmed: Cloudflare (dashboard + API), AWS Route 53 (console), Hostinger (hPanel).
 */
function acceptrics_provider_supports_import($provider) {
    return in_array($provider, ['cloudflare', 'route53', 'hostinger'], true);
}

/**
 * Generate a minimal BIND-format zone file containing the two relay CNAME records.
 * Trailing dots are added to all FQDNs as required by RFC 1035.
 * The gateway record is omitted when $cf_domain is empty (cert not yet issued).
 */
function acceptrics_generate_zone_file($relay_hostname, $cf_domain, $cert_name_short, $cert_value, $site_domain) {
    $cert_fqdn = (!empty($cert_name_short) && !empty($site_domain))
        ? $cert_name_short . '.' . $site_domain
        : '';

    $lines = [
        '; Acceptrics Relay — DNS records',
        '; Zone file import supported by: Cloudflare, AWS Route 53, Hostinger.',
        '; For other providers use these values to add records manually.',
        '$TTL 300',
        '',
    ];

    if (!empty($cert_fqdn) && !empty($cert_value)) {
        $lines[] = '; SSL certificate validation CNAME (add this first — required before gateway)';
        $lines[] = rtrim($cert_fqdn, '.') . '. IN CNAME ' . rtrim($cert_value, '.') . '.';
        $lines[] = '';
    }

    if (!empty($cf_domain) && !empty($relay_hostname)) {
        $lines[] = '; Gateway CNAME — routes your relay subdomain through CloudFront';
        $lines[] = rtrim($relay_hostname, '.') . '. IN CNAME ' . rtrim($cf_domain, '.') . '.';
    }

    return implode("\n", $lines) . "\n";
}

// ---------------------------------------------------------------------------
// Derive initial wizard state from stored options (used by admin-settings.php)
// ---------------------------------------------------------------------------

function acceptrics_relay_get_wizard_state() {
    $token  = get_option('acceptrics_api_token', '');
    $status = get_option('acceptrics_relay_status', '');
    $mode   = get_option('acceptrics_relay_mode', '');

    // Path mode never needs a token — handle its full state machine first.
    if ($mode === 'path') {
        if (empty($status) || $status === 'idle') return 'configure-path';
        if ($status === 'active')                 return 'active';
        if ($status === 'failed')                 return 'failed';
        return 'configure-path';
    }

    // Fresh start — ask which relay type first.
    if (empty($mode)) return 'mode-select';

    // DNS mode: token required before provisioning.
    if (empty($token))                                    return 'token';
    if (empty($status) || $status === 'idle')             return 'configure';
    if ($status === 'active')                             return 'active';
    if ($status === 'failed')                             return 'failed';
    return 'dns'; // pending_cert or provisioning
}

/**
 * Build the full data array passed to relay-setup.js via wp_localize_script.
 */
function acceptrics_relay_get_js_data() {
    $state       = acceptrics_relay_get_wizard_state();
    $site_domain = acceptrics_get_site_domain();
    $relay_mode  = get_option('acceptrics_relay_mode', '');
    $metrics_path = trim(get_option('acceptrics_relay_metrics_path', 'metrics'), '/');

    $provider       = get_option('acceptrics_relay_provider', 'generic');
    $relay_hostname = get_option('acceptrics_relay_hostname', '');
    $cf_domain      = get_option('acceptrics_relay_cf_domain', '');
    $cert_short     = get_option('acceptrics_relay_cert_name_short', '');
    $cert_value     = get_option('acceptrics_relay_cert_value', '');

    // For path mode build per-tag relay URL map and derive the primary hostname.
    $tag_paths_raw = [];
    if ($relay_mode === 'path') {
        $site_host      = parse_url(get_site_url(), PHP_URL_HOST);
        $tag_paths_raw  = (array) get_option('acceptrics_relay_tag_paths', []);
        $primary_tag_id = get_option('acceptrics_relay_tag_id', '');
        if (empty($tag_paths_raw) && !empty($primary_tag_id)) {
            // Backward compat: no per-tag paths stored, fall back to single path.
            $tag_paths_raw = [$primary_tag_id => $metrics_path];
        }
        $primary_path   = !empty($tag_paths_raw[$primary_tag_id])
            ? $tag_paths_raw[$primary_tag_id]
            : ($tag_paths_raw ? reset($tag_paths_raw) : $metrics_path);
        $relay_hostname = $site_host . '/' . $primary_path;
    }

    $data = [
        'ajaxUrl'         => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('acceptrics_relay_nonce'),
        'state'           => $state,
        'relayMode'       => $relay_mode,
        'metricsPath'     => $metrics_path,
        'tokenConnected'  => !empty(get_option('acceptrics_api_token', '')),
        'siteDomain'      => $site_domain,
        'tagId'           => get_option('acceptrics_relay_tag_id', ''),
        'tagIds'          => (function() {
            $ids     = get_option('acceptrics_relay_tag_ids', []);
            $primary = get_option('acceptrics_relay_tag_id', '');
            return (!empty($ids)) ? $ids : (!empty($primary) ? [$primary] : []);
        })(),
        'tagPaths'        => (function () use ($relay_mode, $tag_paths_raw) {
            if ($relay_mode !== 'path' || empty($tag_paths_raw)) return (object) [];
            $site_url = rtrim(get_site_url(), '/');
            $out = [];
            foreach ($tag_paths_raw as $tid => $path) {
                $out[$tid] = $site_url . '/' . $path;
            }
            return $out;
        })(),
        'measurementPath' => get_option('acceptrics_relay_measurement_path', '/t'),
        'activatedAt'     => get_option('acceptrics_relay_activated_at', ''),
        'relayHostname'   => $relay_hostname,
        'certReady'       => (bool) get_option('acceptrics_relay_cert_ready', false),
        'cnameReady'      => (bool) get_option('acceptrics_relay_cname_ready', false),
        'pollingStarted'      => (bool) get_option('acceptrics_relay_polling_started', false),
        'gatewayRecordAdded'  => (bool) get_option('acceptrics_relay_gateway_record_added', false),
        'gatewayCname'    => [
            'name'  => get_option('acceptrics_relay_hostname_short', ''),
            'value' => $cf_domain,
        ],
        'certCname'       => [
            'name'  => $cert_short,
            'value' => $cert_value,
        ],
        'provider'        => $provider,
        'providerLabel'   => get_option('acceptrics_relay_provider_label', 'Unknown provider'),
        'providerSteps'   => get_option('acceptrics_relay_provider_steps', []),
        'providerDeeplink' => acceptrics_get_provider_deeplink($provider, $site_domain),
        'importSupported'  => acceptrics_provider_supports_import($provider),
        'zoneFile'         => acceptrics_generate_zone_file($relay_hostname, $cf_domain, $cert_short, $cert_value, $site_domain),
    ];

    return $data;
}

// ---------------------------------------------------------------------------
// AJAX: Save / validate API token
// ---------------------------------------------------------------------------

add_action('wp_ajax_acceptrics_save_token', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    if (empty($token)) {
        wp_send_json_error('Please paste your API token.');
    }

    // Basic JWT structure check: three base64url segments separated by dots.
    if (substr_count($token, '.') !== 2) {
        wp_send_json_error('Invalid token format. Please paste the complete token from your Acceptrics account (Settings → Developer API).');
    }

    // Validate by calling GET /relay/provision — an endpoint that accepts API key
    // tokens. GET /auth/api-token blocks api_key type tokens by design (403).
    $test = wp_remote_get(ACCEPTRICS_API_URL . '/relay/provision', [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 12,
    ]);

    if (is_wp_error($test)) {
        wp_send_json_error('Could not reach Acceptrics. Please check your internet connection and try again.');
    }

    $code = (int) wp_remote_retrieve_response_code($test);
    if ($code === 401 || $code === 403) {
        wp_send_json_error('Token rejected. Please generate a new token in your Acceptrics account under Settings → Developer API.');
    }
    if ($code >= 500) {
        wp_send_json_error('Acceptrics server error. Please try again in a moment.');
    }

    update_option('acceptrics_api_token', $token);
    wp_send_json_success(['message' => 'Connected.']);
});

// ---------------------------------------------------------------------------
// AJAX: Disconnect token + tear down relay
// ---------------------------------------------------------------------------

add_action('wp_ajax_acceptrics_disconnect_relay', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $status         = get_option('acceptrics_relay_status', '');
    $relay_hostname = get_option('acceptrics_relay_hostname', '');
    if (in_array($status, ['pending_cert', 'provisioning', 'active'], true) && $relay_hostname) {
        acceptrics_api_request('DELETE', '/relay/provision?hostname=' . rawurlencode($relay_hostname));
    }

    foreach ([
        'acceptrics_api_token',
        'acceptrics_relay_mode',
        'acceptrics_relay_metrics_path',
        'acceptrics_relay_degraded_threshold_ms',
        'acceptrics_relay_tag_id',
        'acceptrics_relay_tag_ids',
        'acceptrics_relay_hostname',
        'acceptrics_relay_hostname_short',
        'acceptrics_relay_status',
        'acceptrics_relay_cf_domain',
        'acceptrics_relay_cert_name_short',
        'acceptrics_relay_cert_value',
        'acceptrics_relay_cert_ready',
        'acceptrics_relay_cname_ready',
        'acceptrics_relay_polling_started',
        'acceptrics_relay_gateway_record_added',
        'acceptrics_relay_activated_at',
        'acceptrics_relay_measurement_path',
        'acceptrics_relay_provider',
        'acceptrics_relay_provider_label',
        'acceptrics_relay_provider_steps',
        'acceptrics_relay_tag_paths',
        'acceptrics_relay_metrics_path_base',
    ] as $opt) {
        delete_option($opt);
    }

    // Clear health transients.
    delete_transient('acceptrics_relay_degraded');
    delete_transient('acceptrics_relay_health_times');

    wp_send_json_success();
});

// ---------------------------------------------------------------------------
// AJAX: Provision relay
// ---------------------------------------------------------------------------

add_action('wp_ajax_acceptrics_provision_relay', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    // Primary tag (required)
    $tag_id    = sanitize_text_field(wp_unslash($_POST['tag_id'] ?? ''));
    $subdomain = sanitize_text_field(wp_unslash($_POST['subdomain'] ?? 't'));

    if (!preg_match('/^(G-|GTM-|AW-|DC-|UA-|GT-|GMC-)[A-Z0-9]+$/i', $tag_id)) {
        wp_send_json_error('Invalid Tag ID. Expected format: G-XXXXXXXX, GTM-XXXXXXXX, AW-XXXXXXXX, etc.');
    }

    // Additional tags (optional)
    $extra_tag_ids_raw = isset($_POST['extra_tag_ids']) && is_array($_POST['extra_tag_ids'])
        ? $_POST['extra_tag_ids']
        : [];
    $extra_tag_ids = [];
    foreach ($extra_tag_ids_raw as $et) {
        $et = strtoupper(sanitize_text_field(wp_unslash($et)));
        if (preg_match('/^(G-|GTM-|AW-|DC-|UA-|GT-|GMC-)[A-Z0-9]+$/', $et)) {
            $extra_tag_ids[] = $et;
        }
    }

    $subdomain    = preg_replace('/[^a-z0-9\-]/i', '', $subdomain) ?: 't';
    $site_domain  = acceptrics_get_site_domain();
    $relay_hostname = $subdomain . '.' . $site_domain;
    $tag_id_upper = strtoupper($tag_id);
    $all_tag_ids  = array_values(array_unique(array_merge([$tag_id_upper], $extra_tag_ids)));

    $result = acceptrics_api_request('POST', '/relay/provision', [
        'relayHostname'   => $relay_hostname,
        'tagId'           => $tag_id_upper,
        'measurementPath' => '/t',
    ]);

    if (is_wp_error($result)) {
        $http_status = $result->get_error_data()['status'] ?? 0;
        if ( (int) $http_status === 402 ) {
            wp_send_json_error([
                'code'    => 'relay_billing_required',
                'message' => 'CNAME Relay is a paid add-on ($5/month). Enable it at acceptrics.com/account under Add-ons → CNAME Relay, then return here.',
            ]);
        }
        wp_send_json_error($result->get_error_message());
    }

    $relay_status = $result['status'] ?? 'pending_cert';
    $cf_domain    = $result['gatewayCname']['value'] ?? '';
    $cert_full    = $result['certValidationCname']['name'] ?? '';
    $cert_value   = $result['certValidationCname']['value'] ?? '';
    $cert_short   = acceptrics_strip_cname_domain($cert_full, $site_domain);

    // Detect DNS provider now that we have the site domain.
    $dns_info     = acceptrics_detect_dns_provider($site_domain);
    $provider     = $dns_info['provider'];
    $instructions = acceptrics_get_provider_instructions($provider, $subdomain, $cf_domain, $cert_short, $cert_value);

    // Persist everything.
    update_option('acceptrics_relay_tag_id',           $tag_id_upper);
    update_option('acceptrics_relay_tag_ids',          $all_tag_ids);
    update_option('acceptrics_relay_measurement_path', '/t');
    update_option('acceptrics_relay_hostname',         $relay_hostname);
    update_option('acceptrics_relay_hostname_short',   $subdomain);
    update_option('acceptrics_relay_status',           $relay_status);
    update_option('acceptrics_relay_cf_domain',        $cf_domain);
    update_option('acceptrics_relay_cert_name_short',  $cert_short);
    update_option('acceptrics_relay_cert_value',       $cert_value);
    update_option('acceptrics_relay_cert_ready',       false);
    update_option('acceptrics_relay_cname_ready',      false);
    update_option('acceptrics_relay_polling_started',  false);
    update_option('acceptrics_relay_provider',         $provider);
    update_option('acceptrics_relay_provider_label',   $instructions['label']);
    update_option('acceptrics_relay_provider_steps',   $instructions['steps']);

    wp_send_json_success([
        'relayHostname'   => $relay_hostname,
        'tagId'           => $tag_id_upper,
        'tagIds'          => $all_tag_ids,
        'gatewayCname'    => ['name' => $subdomain,  'value' => $cf_domain],
        'certCname'       => ['name' => $cert_short, 'value' => $cert_value],
        'provider'        => $provider,
        'instructions'    => $instructions,
        'importSupported' => acceptrics_provider_supports_import($provider),
        'providerDeeplink' => acceptrics_get_provider_deeplink($provider, $site_domain),
        'zoneFile'        => acceptrics_generate_zone_file($relay_hostname, $cf_domain, $cert_short, $cert_value, $site_domain),
    ]);
});

// ---------------------------------------------------------------------------
// AJAX: Poll relay status
// ---------------------------------------------------------------------------

add_action('wp_ajax_acceptrics_check_relay_status', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $relay_hostname = get_option('acceptrics_relay_hostname', '');
    $qs      = $relay_hostname ? '?hostname=' . rawurlencode($relay_hostname) : '';
    $result  = acceptrics_api_request('GET', '/relay/provision' . $qs);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    $status      = $result['status'] ?? 'unknown';
    $cert_ready  = !empty($result['checks']['certReady']);
    $cname_ready = !empty($result['checks']['cnameReady']);

    update_option('acceptrics_relay_status',      $status);
    update_option('acceptrics_relay_cert_ready',  $cert_ready);
    update_option('acceptrics_relay_cname_ready', $cname_ready);

    if ($status === 'active' && !empty($result['activatedAt'])) {
        update_option('acceptrics_relay_activated_at', sanitize_text_field($result['activatedAt']));
    }

    // When the cert becomes issued, relay-poll.js creates the CF distribution and
    // the API response will now include gatewayCname — persist it for the DNS step.
    $gateway_cname = $result['gatewayCname'] ?? null;
    if (!empty($gateway_cname['value'])) {
        update_option('acceptrics_relay_cf_domain', $gateway_cname['value']);
    }

    // Regenerate zone file now that we may have a new gateway CNAME value.
    $site_domain    = acceptrics_get_site_domain();
    $relay_hostname = get_option('acceptrics_relay_hostname', '');
    $cf_domain      = get_option('acceptrics_relay_cf_domain', '');
    $cert_short     = get_option('acceptrics_relay_cert_name_short', '');
    $cert_value     = get_option('acceptrics_relay_cert_value', '');
    $provider       = get_option('acceptrics_relay_provider', 'generic');

    wp_send_json_success([
        'status'         => $status,
        'certReady'      => $cert_ready,
        'cnameReady'     => $cname_ready,
        'gatewayCname'   => $gateway_cname,
        'importSupported' => acceptrics_provider_supports_import($provider),
        'zoneFile'        => acceptrics_generate_zone_file($relay_hostname, $cf_domain, $cert_short, $cert_value, $site_domain),
    ]);
});

// ---------------------------------------------------------------------------
// AJAX: Mark that user has clicked "I've added the records" (enables auto-poll on reload)
// ---------------------------------------------------------------------------

add_action('wp_ajax_acceptrics_mark_records_added', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    update_option('acceptrics_relay_polling_started', true);
    wp_send_json_success();
});

add_action('wp_ajax_acceptrics_mark_gateway_added', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    update_option('acceptrics_relay_gateway_record_added', true);
    wp_send_json_success();
});

// ---------------------------------------------------------------------------
// AJAX: Save relay mode choice (mode-select step)
// ---------------------------------------------------------------------------

add_action('wp_ajax_acceptrics_set_relay_mode', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? ''));
    if (!in_array($mode, ['dns', 'path'], true)) {
        wp_send_json_error('Invalid mode.');
    }

    update_option('acceptrics_relay_mode', $mode);
    wp_send_json_success(['mode' => $mode]);
});

// ---------------------------------------------------------------------------
// AJAX: Provision a path-based relay (no CloudFront — activates immediately)
// ---------------------------------------------------------------------------

add_action('wp_ajax_acceptrics_provision_path_relay', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $tag_id       = sanitize_text_field(wp_unslash($_POST['tag_id'] ?? ''));
    $metrics_path = sanitize_text_field(wp_unslash($_POST['metrics_path'] ?? 'metrics'));
    $threshold_ms = (int) ($_POST['threshold_ms'] ?? 800);

    if (!preg_match('/^(G-|GTM-|AW-|DC-|UA-|GT-|GMC-)[A-Z0-9]+$/i', $tag_id)) {
        wp_send_json_error('Invalid Tag ID. Expected format: G-XXXXXXXX, GTM-XXXXXXXX, AW-XXXXXXXX, etc.');
    }

    // Additional tags (optional).
    $extra_raw  = isset($_POST['extra_tag_ids']) && is_array($_POST['extra_tag_ids'])
        ? $_POST['extra_tag_ids'] : [];
    $extra_tags = [];
    foreach ($extra_raw as $et) {
        $et = strtoupper(sanitize_text_field(wp_unslash($et)));
        if (preg_match('/^(G-|GTM-|AW-|DC-|UA-|GT-|GMC-)[A-Z0-9]+$/', $et)) {
            $extra_tags[] = $et;
        }
    }

    $metrics_path = trim(preg_replace('/[^a-z0-9\-_]/i', '', $metrics_path), '/') ?: 'metrics';
    $threshold_ms = max(200, min(5000, $threshold_ms));
    $tag_id_upper = strtoupper($tag_id);
    $all_tags     = array_values(array_unique(array_merge([$tag_id_upper], $extra_tags)));

    // Generate per-tag indexed paths: metrics1, metrics2, etc.
    $tag_paths = [];
    foreach ($all_tags as $i => $tid) {
        $tag_paths[$tid] = $metrics_path . ($i + 1);
    }
    $primary_path = $tag_paths[$tag_id_upper];

    // Notify Acceptrics API that a path relay was provisioned (best-effort).
    acceptrics_api_request('POST', '/relay/provision', [
        'mode'        => 'path',
        'tagId'       => $tag_id_upper,
        'metricsPath' => '/' . $primary_path,
        'siteUrl'     => get_site_url(),
    ]);
    // Ignore errors — path relay works locally without an API response.

    update_option('acceptrics_relay_mode',                  'path');
    update_option('acceptrics_relay_metrics_path',          $primary_path);
    update_option('acceptrics_relay_metrics_path_base',     $metrics_path);
    update_option('acceptrics_relay_tag_paths',             $tag_paths);
    update_option('acceptrics_relay_degraded_threshold_ms', $threshold_ms);
    update_option('acceptrics_relay_tag_id',                $tag_id_upper);
    update_option('acceptrics_relay_tag_ids',               $all_tags);
    update_option('acceptrics_relay_status',                'active');
    update_option('acceptrics_relay_activated_at',          gmdate('c'));
    update_option('acceptrics_relay_measurement_path',      '/' . $primary_path);

    $site_host  = parse_url(get_site_url(), PHP_URL_HOST);
    $relay_base = $site_host . '/' . $primary_path;

    wp_send_json_success([
        'relayBase'   => $relay_base,
        'metricsPath' => $primary_path,
        'tagPaths'    => array_map(function ($p) use ($site_host) {
            return $site_host . '/' . $p;
        }, $tag_paths),
        'tagId'       => $tag_id_upper,
        'tagIds'      => $all_tags,
        'threshold'   => $threshold_ms,
    ]);
});

// ---------------------------------------------------------------------------
// AJAX: Add or remove a tag from an active relay
// ---------------------------------------------------------------------------

add_action('wp_ajax_acceptrics_patch_relay_tags', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $action = sanitize_text_field(wp_unslash($_POST['action_type'] ?? ''));
    $tag_id = strtoupper(sanitize_text_field(wp_unslash($_POST['tag_id'] ?? '')));

    if (!in_array($action, ['add', 'remove'], true)) {
        wp_send_json_error('Invalid action.');
    }
    if (!preg_match('/^(G-|GTM-|AW-|DC-|UA-|GT-|GMC-)[A-Z0-9]+$/', $tag_id)) {
        wp_send_json_error('Invalid tag ID format.');
    }

    $relay_mode     = get_option('acceptrics_relay_mode', 'dns');
    $primary_tag    = get_option('acceptrics_relay_tag_id', '');
    $current_tags   = (array) get_option('acceptrics_relay_tag_ids', []);

    if ($relay_mode === 'path') {
        // Path relay: manage tags locally without calling the API.
        $tag_paths  = (array) get_option('acceptrics_relay_tag_paths', []);
        $base_path  = get_option('acceptrics_relay_metrics_path_base', 'metrics');

        if ($action === 'add') {
            if (!in_array($tag_id, $current_tags, true)) {
                $current_tags[] = $tag_id;
                // Assign the next indexed path (e.g. metrics3 if metrics1 and metrics2 exist).
                $idx = count($tag_paths) + 1;
                $tag_paths[$tag_id] = $base_path . $idx;
                update_option('acceptrics_relay_tag_paths', $tag_paths);
            }
        } else {
            if (count($current_tags) <= 1) {
                wp_send_json_error('Add a replacement tag before removing the last one.');
            }
            $current_tags = array_values(array_filter($current_tags, function ($t) use ($tag_id) {
                return $t !== $tag_id;
            }));
            // Remove the path entry for this tag.
            unset($tag_paths[$tag_id]);
            update_option('acceptrics_relay_tag_paths', $tag_paths);
            // Removing the primary: promote the first remaining tag.
            if ($tag_id === $primary_tag) {
                $primary_tag = $current_tags[0];
                update_option('acceptrics_relay_tag_id', $primary_tag);
            }
        }
        update_option('acceptrics_relay_tag_ids', $current_tags);
        wp_send_json_success(['tags' => $current_tags, 'primaryTag' => $primary_tag]);
        return;
    }

    // DNS relay: sync tag changes through the Acceptrics API so the KVS is updated.
    $relay_hostname = get_option('acceptrics_relay_hostname', '');
    if (empty($relay_hostname)) {
        wp_send_json_error('No active relay found.');
    }

    if ($action === 'remove' && count($current_tags) <= 1) {
        wp_send_json_error('Add a replacement tag before removing the last one.');
    }

    $result = acceptrics_api_request('PATCH', '/relay/provision', [
        'relayHostname' => $relay_hostname,
        'action'        => $action,
        'tagId'         => $tag_id,
    ]);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    $updated_tags = $result['tags'] ?? [];
    if (!empty($updated_tags)) {
        update_option('acceptrics_relay_tag_ids', $updated_tags);
        // If primary was removed, promote the first remaining tag.
        if ($tag_id === $primary_tag && !in_array($primary_tag, $updated_tags, true)) {
            $primary_tag = $updated_tags[0];
            update_option('acceptrics_relay_tag_id', $primary_tag);
        }
    }

    wp_send_json_success(['tags' => $updated_tags, 'primaryTag' => $primary_tag]);
});
