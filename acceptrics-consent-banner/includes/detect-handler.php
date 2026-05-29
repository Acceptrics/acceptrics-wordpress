<?php
if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Blocker detection — receives beacon results from the frontend sampler.
//
// The frontend fires a test request to googletagmanager.com on 10% of page
// loads, then posts here to record whether it succeeded (unblocked) or failed
// (blocked). Results are bucketed by calendar day and stored in a WP option
// for the Report tab to display.
//
// Both nopriv and authed actions are registered so it works for all visitors.
// No nonce: this endpoint records aggregate counts only — there is no user
// data at stake and no meaningful attack surface beyond inflating stats.
// ---------------------------------------------------------------------------

add_action('wp_ajax_nopriv_acceptrics_record_detect', 'acceptrics_record_detect_handler');
add_action('wp_ajax_acceptrics_record_detect',        'acceptrics_record_detect_handler');

// ---------------------------------------------------------------------------
// Adaptive routing aggregate stats — records route decisions without storing
// any visitor-level data. No nonce required (aggregate counts only).
// ---------------------------------------------------------------------------

add_action('wp_ajax_nopriv_acceptrics_record_adaptive_route', 'acceptrics_record_adaptive_route_handler');
add_action('wp_ajax_acceptrics_record_adaptive_route',        'acceptrics_record_adaptive_route_handler');

function acceptrics_record_adaptive_route_handler() {
    $strategy = get_option('acceptrics_relay_strategy', 'adaptive');
    if (!get_option('acceptrics_relay_status', '') === 'active' || !in_array($strategy, ['adaptive', 'always_relay', 'always_direct'], true)) {
        status_header(204);
        exit;
    }

    $valid_routes  = ['direct', 'relay'];
    $valid_reasons = ['direct_ok', 'direct_ok_no_fp_cookie', 'direct_blocked', 'direct_blocked_no_fp_cookie', 'server_degraded', 'no_fp_cookie', 'cached'];
    $valid_types   = ['gtag', 'gtm'];

    $route    = sanitize_text_field(wp_unslash($_POST['route']    ?? ''));
    $reason   = sanitize_text_field(wp_unslash($_POST['reason']   ?? ''));
    $tag_type = sanitize_text_field(wp_unslash($_POST['tag_type'] ?? 'gtag'));

    if (!in_array($route, $valid_routes, true)) {
        status_header(400);
        exit;
    }
    if (!in_array($reason, $valid_reasons, true)) {
        status_header(400);
        exit;
    }
    if (!in_array($tag_type, $valid_types, true)) {
        status_header(400);
        exit;
    }

    $fp_cookie = intval($_POST['fp_cookie'] ?? 0);
    $probe_ms  = max(0, min(10000, intval($_POST['probe_ms'] ?? 0)));

    $today = gmdate('Y-m-d');
    $stats = (array) get_option('acceptrics_adaptive_daily', []);

    if (!isset($stats[$today])) {
        $stats[$today] = [
            'direct'         => 0,
            'relay'          => 0,
            'server_degraded' => 0,
            'direct_blocked' => 0,
            'direct_ok'      => 0,
            'no_fp_cookie'   => 0,
        ];
    }

    // Increment route bucket.
    if ($route === 'direct') {
        $stats[$today]['direct']++;
    } else {
        $stats[$today]['relay']++;
    }

    // Increment reason buckets.
    if ($reason === 'server_degraded') {
        $stats[$today]['server_degraded']++;
    } elseif (in_array($reason, ['direct_blocked', 'direct_blocked_no_fp_cookie'], true)) {
        $stats[$today]['direct_blocked']++;
    } elseif (in_array($reason, ['direct_ok', 'direct_ok_no_fp_cookie'], true)) {
        $stats[$today]['direct_ok']++;
    } elseif ($reason === 'no_fp_cookie') {
        $stats[$today]['no_fp_cookie']++;
    }

    ksort($stats);
    if (count($stats) > 30) {
        $stats = array_slice($stats, -30, 30, true);
    }

    update_option('acceptrics_adaptive_daily', $stats, false);

    status_header(204);
    exit;
}

function acceptrics_record_detect_handler() {
    if (!get_option('acceptrics_blocker_detect_enabled', false)) {
        status_header(204);
        exit;
    }

    $raw = isset($_POST['blocked']) ? intval($_POST['blocked']) : -1;
    if ($raw !== 0 && $raw !== 1) {
        status_header(400);
        exit;
    }
    $blocked = ($raw === 1);

    $today = gmdate('Y-m-d');
    $stats = (array) get_option('acceptrics_detect_daily', []);

    if (!isset($stats[$today])) {
        $stats[$today] = ['blocked' => 0, 'unblocked' => 0];
    }
    if ($blocked) {
        $stats[$today]['blocked']++;
    } else {
        $stats[$today]['unblocked']++;
    }

    // Keep only the most recent 30 days.
    ksort($stats);
    if (count($stats) > 30) {
        $stats = array_slice($stats, -30, 30, true);
    }

    // autoload=false: this option is read only on the Report tab, not on every request.
    update_option('acceptrics_detect_daily', $stats, false);

    status_header(204);
    exit;
}
