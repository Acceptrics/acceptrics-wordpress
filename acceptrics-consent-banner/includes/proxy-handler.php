<?php
if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Path-based relay proxy.
//
// Hooked at init priority 1 — fires before WordPress template routing.
// When relay mode is 'path' and the request URI matches the configured metrics
// path prefix, we forward the request to the appropriate fps.goog endpoint,
// track response latency, and exit before WordPress processes anything else.
//
// Health tracking writes to WP transients so the snippet injector can switch
// server_container_url to direct Google when the server is under load.
// ---------------------------------------------------------------------------

add_action('init', function () {
    if (get_option('acceptrics_relay_mode', 'dns') !== 'path') return;
    if (get_option('acceptrics_relay_status', '') !== 'active') return;

    $tag_paths = (array) get_option('acceptrics_relay_tag_paths', []);

    // Backward compat: no per-tag paths stored yet — use the single metrics_path.
    if (empty($tag_paths)) {
        $single_path = trim(get_option('acceptrics_relay_metrics_path', 'metrics'), '/');
        $primary_tag = strtoupper(get_option('acceptrics_relay_tag_id', ''));
        if ($single_path && $primary_tag) {
            $tag_paths = [$primary_tag => $single_path];
        }
    }
    if (empty($tag_paths)) return;

    $uri      = $_SERVER['REQUEST_URI'] ?? '';
    $uri_path = explode('?', $uri, 2)[0]; // path only, no query string
    $qs       = $_SERVER['QUERY_STRING'] ?? '';

    // Find which tag path matches this request URI.
    $effective      = null;
    $matched_prefix = null;
    foreach ($tag_paths as $tid => $path) {
        $prefix = '/' . trim($path, '/') . '/';
        $root   = rtrim($prefix, '/'); // /metricsN without trailing slash
        if ($uri_path === $root || strncmp($uri_path, $prefix, strlen($prefix)) === 0) {
            $effective      = strtoupper($tid);
            $matched_prefix = $prefix;
            break;
        }
    }
    if ($effective === null) return;

    // Sub-path after the prefix, leading slash preserved (e.g. /gtag/js).
    // Root access (/metricsN or /metricsN/) yields sub = '/'.
    if ($uri_path === rtrim($matched_prefix, '/')) {
        $sub = '/';
    } else {
        $sub = substr($uri_path, strlen($matched_prefix) - 1);
    }

    // Local health-check — never forwarded to Google.
    if ($sub === '/healthy') {
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'ok';
        exit;
    }

    // -----------------------------------------------------------------------
    // Request budget — optional circuit breaker (0 = disabled).
    // -----------------------------------------------------------------------
    $rpm_limit = (int) get_option('acceptrics_relay_max_requests_per_minute', 0);
    if ($rpm_limit > 0) {
        $bucket = 'acceptrics_relay_rpm_' . gmdate('YmdHi');
        $count  = (int) wp_cache_get($bucket, 'acceptrics');
        if ($count === false) $count = 0;
        $count++;
        wp_cache_set($bucket, $count, 'acceptrics', 60);

        if ($count > $rpm_limit) {
            set_transient('acceptrics_relay_degraded', 1, ACCEPTRICS_DEGRADED_TTL_S);
        }
    }

    // validate_geo probe issued by Google during GTG enrollment verification.
    // Must always reach fps.goog regardless of circuit-breaker state — Google
    // expects a passthrough response, not a local echo or a CDN redirect.
    if ($qs === 'validate_geo=healthy') {
        acceptrics_proxy_to_fps($effective, $sub, $qs);
        exit;
    }

    // -----------------------------------------------------------------------
    // Circuit breaker — short-circuit before cURL when relay is degraded.
    // -----------------------------------------------------------------------
    $degraded = (bool) get_transient('acceptrics_relay_degraded');
    if ($degraded) {
        $is_script_load = in_array($sub, ['/', '/gtag/js', '/gtm.js'], true);
        if ($is_script_load) {
            $is_gtm   = strpos($effective, 'GTM-') === 0;
            $redir    = $is_gtm
                ? 'https://www.googletagmanager.com/gtm.js?id=' . $effective
                : 'https://www.googletagmanager.com/gtag/js?id=' . $effective;
            header('Location: ' . $redir, true, 302);
            header('Cache-Control: no-store');
            exit;
        }
        // Collection/event hits — drop silently.
        status_header(204);
        header('Cache-Control: no-store');
        exit;
    }

    acceptrics_proxy_to_fps($effective, $sub, $qs);
    exit;
}, 1);

// ---------------------------------------------------------------------------
// Forward a request to $tag_id.fps.goog and stream the response back.
// ---------------------------------------------------------------------------

function acceptrics_proxy_to_fps($tag_id, $sub_path, $query_string) {
    $fps_host = strtolower($tag_id) . '.fps.goog';
    $target   = 'https://' . $fps_host . $sub_path;
    if ($query_string !== '') {
        $target .= '?' . $query_string;
    }

    $srv     = $_SERVER;
    $headers = [];

    // Forward browser headers that fps.goog uses for attribution and cookies.
    // Accept-Encoding is intentionally excluded — we request identity encoding
    // from fps.goog so the response body is plain bytes PHP can safely stream.
    // HTTP_COOKIE is excluded from this map and filtered separately below.
    $forward_map = [
        'HTTP_USER_AGENT'      => 'User-Agent',
        'HTTP_ACCEPT'          => 'Accept',
        'HTTP_ACCEPT_LANGUAGE' => 'Accept-Language',
        'HTTP_REFERER'         => 'Referer',
    ];
    foreach ($forward_map as $srv_key => $hdr_name) {
        if (!empty($srv[$srv_key])) {
            $headers[] = $hdr_name . ': ' . $srv[$srv_key];
        }
    }
    $headers[] = 'Accept-Encoding: identity';
    $headers[] = 'X-Gtg-Developer-Id: dYWU2OD';

    // Forward only analytics cookies — never WordPress auth tokens.
    // GDPR data minimisation: fps.goog does not need session auth credentials.
    if (!empty($srv['HTTP_COOKIE'])) {
        $safe_cookies = [];
        foreach (explode('; ', $srv['HTTP_COOKIE']) as $c) {
            $name = trim(explode('=', $c, 2)[0]);
            if (preg_match('/^(_ga|_gid|_gcl|_gac|__utm|FPID|FPLC|IDE|NID)/', $name)) {
                $safe_cookies[] = $c;
            }
        }
        if ($safe_cookies) {
            $headers[] = 'Cookie: ' . implode('; ', $safe_cookies);
        }
    }

    // Resolve the real visitor IP. Behind a reverse proxy (Cloudflare, load
    // balancer, etc.) REMOTE_ADDR is the proxy's IP, not the visitor's.
    // CF-Connecting-IP is the most reliable source when behind Cloudflare;
    // otherwise take the first entry from the incoming X-Forwarded-For chain;
    // fall back to REMOTE_ADDR only for direct (non-proxied) connections.
    // Priority order for real visitor IP across common hosting stacks:
    //   CF-Connecting-IP   — Cloudflare (all plans)
    //   True-Client-IP     — Cloudflare Enterprise, Akamai
    //   X-Forwarded-For[0] — standard reverse-proxy chain (nginx, Apache, AWS ALB, etc.)
    //   X-Real-IP          — nginx single-IP variant (more common on cPanel/Plesk hosts)
    //   REMOTE_ADDR        — last resort: direct connection or unrecognised proxy stack
    $visitor_ip     = '';
    $visitor_ip_src = '';
    if (!empty($srv['HTTP_CF_CONNECTING_IP'])) {
        $visitor_ip     = $srv['HTTP_CF_CONNECTING_IP'];
        $visitor_ip_src = 'CF-Connecting-IP';
    } elseif (!empty($srv['HTTP_TRUE_CLIENT_IP'])) {
        $visitor_ip     = $srv['HTTP_TRUE_CLIENT_IP'];
        $visitor_ip_src = 'True-Client-IP';
    } elseif (!empty($srv['HTTP_X_FORWARDED_FOR'])) {
        $visitor_ip     = trim(explode(',', $srv['HTTP_X_FORWARDED_FOR'])[0]);
        $visitor_ip_src = 'X-Forwarded-For';
    } elseif (!empty($srv['HTTP_X_REAL_IP'])) {
        $visitor_ip     = $srv['HTTP_X_REAL_IP'];
        $visitor_ip_src = 'X-Real-IP';
    } elseif (!empty($srv['REMOTE_ADDR'])) {
        $visitor_ip     = $srv['REMOTE_ADDR'];
        $visitor_ip_src = 'REMOTE_ADDR';
    }
    if ($visitor_ip) {
        $headers[] = 'X-Forwarded-For: ' . $visitor_ip;
    }

    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log('[acceptrics-relay] ip-resolve:'
            . ' src='         . $visitor_ip_src
            . ' ip='          . $visitor_ip
            . ' REMOTE_ADDR=' . ($srv['REMOTE_ADDR'] ?? '(none)')
            . ' CF-IP='       . ($srv['HTTP_CF_CONNECTING_IP'] ?? '(none)')
            . ' XFF='         . ($srv['HTTP_X_FORWARDED_FOR'] ?? '(none)')
        );
    }

    // Forward geo headers to fps.goog using GeoIP Detect plugin if installed.
    // geoip_detect2_get_info_from_current_ip() resolves the visitor's IP
    // automatically, respecting the plugin's trusted-proxy configuration.
    // Without these headers fps.goog still routes via X-Forwarded-For but
    // ?validate_geo=healthy will return 500. Install geoip-detect to fix that.
    if (function_exists('geoip_detect2_get_info_from_current_ip')) {
        $info    = @geoip_detect2_get_info_from_current_ip();
        $country = $info->country->isoCode ?? '';
        $region  = $info->mostSpecificSubdivision->isoCode ?? '';
        $city    = $info->city->name ?? '';
        $lat     = $info->location->latitude ?? null;
        $lon     = $info->location->longitude ?? null;

        if ($country) {
            $headers[] = 'X-Forwarded-Country: ' . $country;
            if ($region) {
                $headers[] = 'X-Forwarded-Region: ' . $region;
                $headers[] = 'X-Forwarded-CountryRegion: ' . $country . '-' . $region;
            } else {
                $headers[] = 'X-Forwarded-CountryRegion: ' . $country;
            }
        }
        if ($lat !== null && $lon !== null) {
            $geo_str  = 'latlong=' . $lat . ',' . $lon;
            $geo_str .= $city ? ';city=' . $city : '';
            $headers[] = 'X-Forwarded-Geolocation: ' . $geo_str;
        }
    }

    $start = microtime(true);

    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER         => true, // include response headers in return value
    ]);

    if (strtoupper($srv['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $post_body = file_get_contents('php://input');
        curl_setopt($ch, CURLOPT_POST, true);
        if ($post_body !== false && $post_body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
        }
    }

    $raw      = curl_exec($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdr_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $elapsed  = (int) round((microtime(true) - $start) * 1000);
    $success  = ($raw !== false && $code > 0);
    curl_close($ch);

    acceptrics_relay_record_health($elapsed, $success);

    if (!$success) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[acceptrics-relay] proxy failed: code=' . $code . ' elapsed=' . $elapsed . 'ms target=' . $target);
        }
        status_header(502);
        return;
    }

    $resp_body    = substr($raw, $hdr_size);
    $resp_headers = explode("\r\n", substr($raw, 0, $hdr_size));

    status_header($code);

    // Forward a safe subset of response headers.
    $passthrough = ['content-type', 'cache-control', 'vary', 'set-cookie'];
    foreach ($resp_headers as $h) {
        $lower = strtolower($h);
        foreach ($passthrough as $pt) {
            if (strncmp($lower, $pt . ':', strlen($pt) + 1) === 0) {
                header($h, false);
                break;
            }
        }
    }

    echo $resp_body;
}

// ---------------------------------------------------------------------------
// Health tracking — rolling buffer of response times stored in a transient.
// When the median of recent calls exceeds the threshold the relay is marked
// degraded and the snippet injector falls back to direct Google endpoints
// for the next ACCEPTRICS_DEGRADED_TTL_S seconds before retrying.
// ---------------------------------------------------------------------------

define('ACCEPTRICS_HEALTH_BUFFER',    20);  // number of samples to keep
define('ACCEPTRICS_HEALTH_WINDOW_S', 300);  // transient lifetime (5 min)
define('ACCEPTRICS_DEGRADED_TTL_S',   60);  // fallback window before retry

function acceptrics_relay_record_health($elapsed_ms, $success) {
    $threshold = (int) get_option('acceptrics_relay_degraded_threshold_ms', 800);

    // Always record failures; sample successes at 1-in-N to reduce write load.
    if ($success) {
        $sample = max(1, (int) get_option('acceptrics_relay_health_success_sample', 20));
        if (mt_rand(1, $sample) !== 1) {
            return;
        }
    }

    // Throttle transient writes for success samples.
    $min_gap = max(1, (int) get_option('acceptrics_relay_health_min_write_gap_s', 5));
    if ($success) {
        $last_write = (int) get_transient('acceptrics_relay_health_last_write');
        if ($last_write && (time() - $last_write) < $min_gap) {
            return;
        }
        set_transient('acceptrics_relay_health_last_write', time(), 60);
    }

    $times   = (array) get_transient('acceptrics_relay_health_times');
    $times[] = $elapsed_ms;
    if (count($times) > ACCEPTRICS_HEALTH_BUFFER) {
        array_shift($times);
    }
    set_transient('acceptrics_relay_health_times', $times, ACCEPTRICS_HEALTH_WINDOW_S);

    $sorted = $times;
    sort($sorted);
    $median = $sorted[(int) floor(count($sorted) / 2)];

    if (!$success || $median > $threshold) {
        set_transient('acceptrics_relay_degraded', 1, ACCEPTRICS_DEGRADED_TTL_S);
    } else {
        delete_transient('acceptrics_relay_degraded');
    }
}

// ---------------------------------------------------------------------------
// AJAX: Return current relay health for admin UI polling.
// ---------------------------------------------------------------------------

add_action('wp_ajax_acceptrics_get_relay_health', function () {
    check_ajax_referer('acceptrics_relay_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $degraded  = (bool) get_transient('acceptrics_relay_degraded');
    $times     = (array) get_transient('acceptrics_relay_health_times');
    $threshold = (int) get_option('acceptrics_relay_degraded_threshold_ms', 800);

    $stats = [];
    if (count($times) > 0) {
        $sorted       = $times;
        sort($sorted);
        $n            = count($sorted);
        $stats['p50'] = $sorted[(int) floor($n / 2)];
        $stats['p95'] = $sorted[max(0, (int) ceil($n * 0.95) - 1)];
        $stats['last'] = end($times);
        $stats['count'] = $n;
    }

    wp_send_json_success([
        'degraded'  => $degraded,
        'threshold' => $threshold,
        'stats'     => $stats,
    ]);
});
