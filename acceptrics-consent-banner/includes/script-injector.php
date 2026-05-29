<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inject the account-specific Acceptrics banner script into <head> on every page.
 * Uses priority 1 to load as early as possible.
 */
function acceptrics_inject_head_script() {
    $account_id = get_option('acceptrics_account_id', '');
    if (empty($account_id)) {
        return;
    }
    // Account ID is sanitized on save (alphanumeric, hyphens, underscores only).
    $safe_id = esc_attr($account_id);
    echo '<script async rel="dns-prefetch" type="text/javascript" src="https://acct.acceptrics.com/' . $safe_id . '"></script>' . "\n";
}
add_action('wp_head', 'acceptrics_inject_head_script', 1);

/**
 * Inject the Google tag snippet routing through the Acceptrics Relay when the
 * relay is active.  Runs at priority 2 (just after the consent banner at 1).
 *
 * For GA4  (G-XXXXXXXX):  loads gtag.js from the relay subdomain.
 * For GTM  (GTM-XXXXXX):  loads gtm.js from the relay subdomain.
 *
 * The relay subdomain is a customer-owned CNAME that proxies to CloudFront,
 * so the browser sees a first-party origin — defeating ad blockers and
 * enabling first-party cookies.
 */
function acceptrics_inject_relay_script() {
    if (get_option('acceptrics_relay_status', '') !== 'active') {
        return;
    }

    $tag_id  = get_option('acceptrics_relay_tag_id', '');
    $tag_ids = (array) get_option('acceptrics_relay_tag_ids', []);
    if (empty($tag_ids) && !empty($tag_id)) {
        $tag_ids = [$tag_id];
    }

    if (empty($tag_id) || empty($tag_ids)) {
        return;
    }

    $relay_mode = get_option('acceptrics_relay_mode', 'dns');

    if ($relay_mode === 'path') {
        acceptrics_inject_path_relay_script($tag_id, $tag_ids);
    } else {
        $hostname = get_option('acceptrics_relay_hostname', '');
        if (empty($hostname)) return;
        acceptrics_inject_dns_relay_script($tag_id, $tag_ids, $hostname);
    }
}

/**
 * Emit the GTG spec race-condition prevention script (Script 1).
 * Registers all tag IDs in google_tags_first_party before any tag loads.
 */
function acceptrics_gtg_race_condition_script($tag_ids) {
    $json = wp_json_encode(array_values((array) $tag_ids));
    return '<script>(function(w,i,g){w[g]=w[g]||[];if(typeof w[g].push==\'function\')w[g].push.apply(w[g],i)})(window,' . $json . ',\'google_tags_first_party\');</script>' . "\n";
}

function acceptrics_inject_dns_relay_script($tag_id, $tag_ids, $hostname) {
    $safe_host = esc_attr($hostname);

    // Script 1: race condition prevention (GTG spec, both GTM and non-GTM).
    echo acceptrics_gtg_race_condition_script($tag_ids); // phpcs:ignore WordPress.Security.EscapeOutput

    if (strpos($tag_id, 'GTM-') === 0) {
        // Script 2: GTM IIFE — no tag ID in the URL per GTG spec.
        ?>
<!-- Google Tag Manager (via Acceptrics Relay) -->
<script>(function(w,d,s,l){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s);j.async=true;j.src=
'https://<?php echo $safe_host; ?>/';f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer');</script>
<!-- End Google Tag Manager -->
        <?php
    } else {
        // Script 2: load tag through relay (trailing slash = measurement path root).
        // Script 3: configure and initialise.
        ?>
<!-- Google tag (via Acceptrics Relay) -->
<script async src="https://<?php echo $safe_host; ?>/"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
<?php foreach ($tag_ids as $tid) : ?>
gtag('config', '<?php echo esc_js($tid); ?>', { 'server_container_url': 'https://<?php echo $safe_host; ?>' });
<?php endforeach; ?>
</script>
<!-- End Google tag -->
        <?php
    }
}

function acceptrics_inject_path_relay_script($tag_id, $tag_ids) {
    $site_url  = rtrim(get_site_url(), '/');
    $degraded  = (bool) get_transient('acceptrics_relay_degraded');
    $strategy  = get_option('acceptrics_relay_strategy', 'adaptive');

    // Build per-tag relay URL map.
    $tag_paths_raw = (array) get_option('acceptrics_relay_tag_paths', []);
    if (empty($tag_paths_raw)) {
        // Backward compat: single path applies to all tags.
        $single_path = trim(get_option('acceptrics_relay_metrics_path', 'metrics'), '/');
        foreach ($tag_ids as $tid) {
            $tag_paths_raw[$tid] = $single_path;
        }
    }
    $tag_relay_urls = [];
    foreach ($tag_ids as $tid) {
        if (!empty($tag_paths_raw[$tid])) {
            $tag_relay_urls[$tid] = $site_url . '/' . $tag_paths_raw[$tid];
        }
    }
    $primary_relay_url = !empty($tag_relay_urls[$tag_ids[0]])
        ? $tag_relay_urls[$tag_ids[0]]
        : ($site_url . '/' . trim(get_option('acceptrics_relay_metrics_path', 'metrics'), '/'));

    if ($strategy === 'adaptive') {
        acceptrics_inject_adaptive_script($tag_id, $tag_ids, $primary_relay_url, $degraded, $tag_relay_urls);
        return;
    }

    if ($strategy === 'always_direct') {
        acceptrics_inject_direct_script($tag_id, $tag_ids);
        return;
    }

    // always_relay — one script tag per relay path, per-tag server_container_url.
    if ($degraded) {
        $primary = esc_attr($tag_ids[0]);
        ?>
<!-- Google tag (via Acceptrics Relay — fallback mode) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $primary; ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
<?php foreach ($tag_ids as $tid) : ?>
gtag('config', '<?php echo esc_js($tid); ?>');
<?php endforeach; ?>
</script>
<!-- End Google tag -->
        <?php
        return;
    }

    // Script 1: race condition prevention (GTG spec, both GTM and non-GTM).
    echo acceptrics_gtg_race_condition_script($tag_ids); // phpcs:ignore WordPress.Security.EscapeOutput

    if (strpos($tag_id, 'GTM-') === 0) {
        // Script 2: GTM IIFE — measurement path root, no tag ID in URL per GTG spec.
        $safe_base = esc_attr(rtrim($primary_relay_url, '/'));
        ?>
<!-- Google Tag Manager (via Acceptrics Relay) -->
<script>(function(w,d,s,l){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s);j.async=true;j.src=
'<?php echo $safe_base; ?>/';f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer');</script>
<!-- End Google Tag Manager -->
        <?php
        return;
    }

    // Script 2: one async load per relay path (measurement path with trailing slash).
    // Script 3: configure and initialise each tag with its per-tag relay URL.
    ?>
<!-- Google tag (via Acceptrics Relay) -->
<?php foreach ($tag_ids as $tid) :
    $src = esc_attr(rtrim($tag_relay_urls[$tid] ?? $primary_relay_url, '/') . '/');
?>
<script async src="<?php echo $src; ?>"></script>
<?php endforeach; ?>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
<?php foreach ($tag_ids as $tid) :
    $scurl = esc_attr($tag_relay_urls[$tid] ?? $primary_relay_url);
?>
gtag('config', '<?php echo esc_js($tid); ?>', { 'server_container_url': '<?php echo $scurl; ?>' });
<?php endforeach; ?>
</script>
<!-- End Google tag -->
    <?php
}

function acceptrics_inject_adaptive_script($tag_id, $tag_ids, $relay_base, $degraded, $tag_relay_urls = []) {
    $primary = $tag_ids[0];
    $is_gtm  = strpos($tag_id, 'GTM-') === 0;

    $tag_paths_config = !empty($tag_relay_urls)
        ? $tag_relay_urls
        : [$primary => $relay_base];

    $config = [
        'strategy'        => 'adaptive',
        'tagType'         => $is_gtm ? 'gtm' : 'gtag',
        'primaryTag'      => $primary,
        'tagIds'          => array_values($tag_ids),
        'relayBase'       => $relay_base,
        'primaryRelayUrl' => $relay_base,
        'tagPaths'        => $tag_paths_config,
        'directGtagSrc'   => 'https://www.googletagmanager.com/gtag/js?id=' . $primary,
        'directGtmSrc'    => 'https://www.googletagmanager.com/gtm.js?id=' . $primary,
        'degraded'        => $degraded,
        'routerVersion'   => (int) get_option('acceptrics_adaptive_router_version', 1),
        'probeTimeoutMs'  => (int) get_option('acceptrics_adaptive_probe_timeout_ms', 900),
        'directTtlMs'     => (int) get_option('acceptrics_adaptive_direct_ttl_s', 86400) * 1000,
        'relayTtlMs'      => (int) get_option('acceptrics_adaptive_relay_ttl_s', 14400) * 1000,
        'statsSampleRate' => (float) get_option('acceptrics_adaptive_stats_sample_rate', 0.10),
        'requireFpCookie' => (bool) get_option('acceptrics_adaptive_require_fp_cookie', false),
        'statsUrl'        => admin_url('admin-ajax.php'),
    ];

    $router_file = plugin_dir_path(__FILE__) . 'js/adaptive-router.js';
    ?>
<!-- Google tag (via Acceptrics Adaptive Routing) -->
<script>window.acptGtg = <?php echo wp_json_encode($config); ?>;</script>
<script>
<?php
    if (file_exists($router_file)) {
        readfile($router_file); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }
?>
</script>
<!-- End Google tag -->
    <?php
}

function acceptrics_inject_direct_script($tag_id, $tag_ids) {
    $primary = esc_attr($tag_ids[0]);
    if (strpos($tag_id, 'GTM-') === 0) {
        $safe_tag = esc_attr($tag_id);
        ?>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo $safe_tag; ?>');</script>
<!-- End Google Tag Manager -->
        <?php
        return;
    }
    ?>
<!-- Google tag -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $primary; ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
<?php foreach ($tag_ids as $tid) : ?>
gtag('config', '<?php echo esc_js($tid); ?>');
<?php endforeach; ?>
</script>
<!-- End Google tag -->
    <?php
}
add_action('wp_head', 'acceptrics_inject_relay_script', 2);

/**
 * Inject the blocker detection beacon on 10% of frontend page loads.
 *
 * Fires a test image request to googletagmanager.com. If it fails the visitor
 * likely has an ad blocker or browser restriction. The result is posted to the
 * acceptrics_record_detect AJAX endpoint so the Report tab can show uplift data.
 * Only active when acceptrics_blocker_detect_enabled is on.
 */
function acceptrics_inject_detect_beacon() {
    if (!get_option('acceptrics_blocker_detect_enabled', false)) {
        return;
    }
    $tag_id = get_option('acceptrics_relay_tag_id', '');
    if (empty($tag_id)) {
        return;
    }
    $ajax_url = esc_js(admin_url('admin-ajax.php'));
    $safe_tag = esc_js($tag_id);
    ?>
<script>window.acptDetect={ajax:"<?php echo $ajax_url; ?>",tag:"<?php echo $safe_tag; ?>"};</script>
<script>(function(){var d=window.acptDetect;if(!d||Math.random()>=0.1)return;function s(b){var x=new XMLHttpRequest();x.open("POST",d.ajax,true);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");x.send("action=acceptrics_record_detect&blocked="+b);}var i=new Image();i.onload=function(){s(0);};i.onerror=function(){s(1);};i.src="https://www.googletagmanager.com/gtag/js?id="+d.tag+"&_t="+Date.now();})();</script>
    <?php
}
add_action('wp_head', 'acceptrics_inject_detect_beacon', 3);

/**
 * Enqueue the WP Consent API listener when the banner is enabled.
 * The listener bridges Acceptrics consent events with the WP Consent API.
 */
function acceptrics_enqueue_consent_listener() {
    if (!get_option('acceptrics_enable_banner')) {
        return;
    }

    wp_enqueue_script(
        'acceptrics-wp-consent-hook',
        plugin_dir_url(__FILE__) . 'js/listener.js',
        array(),
        ACCEPTRICS_VERSION,
        true
    );
}
add_action('wp_enqueue_scripts', 'acceptrics_enqueue_consent_listener');
