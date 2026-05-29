(function () {
    'use strict';

    var cfg = window.acptGtg;
    if (!cfg) return;

    // ---------------------------------------------------------------------------
    // dataLayer + gtag bootstrap — must exist before any consent or config call.
    // ---------------------------------------------------------------------------

    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { dataLayer.push(arguments); };

    // ---------------------------------------------------------------------------
    // Consent defaults — fired before Google scripts load.
    // ---------------------------------------------------------------------------

    gtag('consent', 'default', {
        ad_storage:        'denied',
        ad_user_data:      'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied',
        wait_for_update:   500,
    });

    function applyStoredConsent() {
        try {
            var raw = localStorage.getItem('__acceptrics_settings');
            if (!raw) return;
            var s = JSON.parse(raw);
            if (!s || !s.purposes) return;
            var analyticsOk = s.purposes.analytics === 'accepted';
            var adsOk       = s.purposes.ads === 'accepted';
            gtag('consent', 'update', {
                analytics_storage:  analyticsOk ? 'granted' : 'denied',
                ad_storage:         adsOk ? 'granted' : 'denied',
                ad_user_data:       adsOk ? 'granted' : 'denied',
                ad_personalization: adsOk ? 'granted' : 'denied',
            });
        } catch (e) {}
    }

    applyStoredConsent();
    document.addEventListener('__acceptrics_consent_updated', applyStoredConsent);

    // ---------------------------------------------------------------------------
    // Script loader
    // ---------------------------------------------------------------------------

    function loadScript(src, onload) {
        var s = document.createElement('script');
        s.async = true;
        s.src = src;
        if (onload) s.onload = onload;
        var ref = document.head || document.documentElement;
        ref.insertBefore(s, ref.firstChild);
    }

    // ---------------------------------------------------------------------------
    // Tag initialization
    // ---------------------------------------------------------------------------

    function initTag(route) {
        var primaryUrl = cfg.primaryRelayUrl || cfg.relayBase;
        var ids        = cfg.tagIds || [cfg.primaryTag];
        var tagPaths   = cfg.tagPaths || {};

        if (cfg.tagType === 'gtm') {
            var gtmSrc = (route === 'relay')
                ? (primaryUrl + '/gtm.js?id=' + cfg.primaryTag)
                : cfg.directGtmSrc;
            // GTM self-initializes via its own snippet logic; just load the script.
            loadScript(gtmSrc);
            return;
        }

        if (route === 'relay') {
            // Load the primary script; once ready, configure every tag with its
            // own per-tag relay URL so hits route to the right fps.goog endpoint.
            loadScript(primaryUrl + '/', function () {
                gtag('js', new Date());
                for (var j = 0; j < ids.length; j++) {
                    var tagUrl = tagPaths[ids[j]] || primaryUrl;
                    gtag('config', ids[j], { server_container_url: tagUrl });
                }
            });
            // Load scripts for all secondary relay paths so each endpoint
            // also receives a script-load hit (configs are handled above).
            for (var i = 1; i < ids.length; i++) {
                var secUrl = tagPaths[ids[i]] || primaryUrl;
                if (secUrl !== primaryUrl) {
                    loadScript(secUrl + '/');
                }
            }
        } else {
            loadScript(cfg.directGtagSrc, function () {
                gtag('js', new Date());
                for (var j = 0; j < ids.length; j++) {
                    gtag('config', ids[j]);
                }
            });
        }
    }

    // ---------------------------------------------------------------------------
    // Client-side route cache (localStorage)
    // ---------------------------------------------------------------------------

    var CACHE_KEY = 'acpt_gtg_route:v' + (cfg.routerVersion || 1)
        + ':' + cfg.primaryTag
        + ':' + (cfg.primaryRelayUrl || cfg.relayBase);

    function readCache() {
        try {
            var raw = localStorage.getItem(CACHE_KEY);
            if (!raw) return null;
            var entry = JSON.parse(raw);
            if (!entry || !entry.route || !entry.exp) return null;
            if (Date.now() > entry.exp) return null;
            return entry;
        } catch (e) {
            return null;
        }
    }

    function writeCache(route, reason, fpCookieOk, ttlMs) {
        try {
            localStorage.setItem(CACHE_KEY, JSON.stringify({
                route:      route,
                reason:     reason,
                fpCookieOk: fpCookieOk,
                exp:        Date.now() + ttlMs,
            }));
        } catch (e) {}
    }

    // ---------------------------------------------------------------------------
    // First-party cookie probe
    // ---------------------------------------------------------------------------

    function probeFpCookie() {
        try {
            document.cookie = '__acpt_fp_probe=1; path=/; max-age=60; SameSite=Lax; Secure';
            return document.cookie.indexOf('__acpt_fp_probe=1') !== -1;
        } catch (e) {
            return false;
        }
    }

    // ---------------------------------------------------------------------------
    // Direct Google probe — resolves true if reachable, false on timeout/error.
    // ---------------------------------------------------------------------------

    function probeDirect(callback) {
        var url = 'https://www.googletagmanager.com/gtag/js?id='
            + cfg.primaryTag + '&_acpt_probe=' + Date.now();
        var done  = false;
        var timer = setTimeout(function () {
            if (!done) { done = true; callback(false); }
        }, cfg.probeTimeoutMs || 900);

        // no-cors is required for cross-origin probes; redirect must be 'follow'
        // (the spec forbids 'error' with no-cors — it throws a TypeError immediately).
        //
        // To catch adblocker/extension intercepts we inspect Resource Timing after
        // the fetch resolves:
        //
        //   1. redirectCount > 0  — catches HTTP-level 307s (some browsers expose
        //      this even for cross-origin resources without Timing-Allow-Origin).
        //
        //   2. duration < 10 ms  — catches extension WebRequest-API redirects to
        //      local stubs (e.g. chrome-extension://…/google-analytics_analytics.js).
        //      Real CDN responses always incur at least one TCP+TLS round-trip
        //      (≥ ~15 ms minimum). A sub-10 ms resolution means the request never
        //      left the browser. `duration` (responseEnd − startTime) is NOT a
        //      timestamp and IS exposed for cross-origin resources without TAO.
        fetch(url, { mode: 'no-cors', cache: 'no-store', credentials: 'omit' })
            .then(function () {
                clearTimeout(timer);
                if (done) return;
                done = true;
                try {
                    var entries = performance.getEntriesByName(url, 'resource');
                    if (entries.length > 0) {
                        var e = entries[0];
                        if (e.redirectCount > 0) { callback(false); return; }
                        if (e.duration > 0 && e.duration < 10) { callback(false); return; }
                    }
                } catch (ex) {}
                callback(true);
            })
            .catch(function () {
                clearTimeout(timer);
                if (!done) { done = true; callback(false); }
            });
    }

    // ---------------------------------------------------------------------------
    // Aggregate stats — sampled, sent via sendBeacon
    // ---------------------------------------------------------------------------

    function sendStats(route, reason, fpCookieOk, fromCache, probeMs) {
        if (Math.random() >= (cfg.statsSampleRate != null ? cfg.statsSampleRate : 0.1)) return;
        var data = new FormData();
        data.append('action',    'acceptrics_record_adaptive_route');
        data.append('route',     route);
        data.append('reason',    reason);
        data.append('fp_cookie', fpCookieOk ? '1' : '0');
        data.append('cache',     fromCache  ? '1' : '0');
        data.append('probe_ms',  String(probeMs || 0));
        data.append('tag_type',  cfg.tagType || 'gtag');

        if (navigator.sendBeacon) {
            navigator.sendBeacon(cfg.statsUrl, data);
        } else {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', cfg.statsUrl, true);
            xhr.send(data);
        }
    }

    // ---------------------------------------------------------------------------
    // Route decision state machine
    // ---------------------------------------------------------------------------

    function decide() {
        if (cfg.strategy === 'always_direct') {
            initTag('direct');
            return;
        }

        if (cfg.strategy === 'always_relay') {
            initTag('relay');
            return;
        }

        // Server has flagged relay as degraded — go direct without probing.
        if (cfg.degraded) {
            initTag('direct');
            sendStats('direct', 'server_degraded', false, false, 0);
            return;
        }

        // Use cached decision if still valid.
        var cached = readCache();
        if (cached) {
            initTag(cached.route);
            sendStats(cached.route, 'cached', !!cached.fpCookieOk, true, 0);
            return;
        }

        // No cached decision — load relay immediately (it is same-origin and
        // always works regardless of content blockers or extension intercepts).
        // Probe Google in the background; the result is written to cache so the
        // *next* visit can use direct if confirmed. This removes the probe from
        // the critical path and eliminates all false-negative edge cases.
        var fpOk = probeFpCookie();
        initTag('relay');

        var probeStart = Date.now();
        probeDirect(function (directOk) {
            var probeMs = Date.now() - probeStart;
            var route, reason, ttlMs;

            if (directOk) {
                route  = 'direct';
                reason = fpOk ? 'direct_ok' : 'direct_ok_no_fp_cookie';
                ttlMs  = cfg.directTtlMs || 86400000;
            } else if (cfg.requireFpCookie && !fpOk) {
                route  = 'direct';
                reason = 'no_fp_cookie';
                ttlMs  = Math.min(cfg.relayTtlMs || 14400000, 3600000);
            } else {
                route  = 'relay';
                reason = fpOk ? 'direct_blocked' : 'direct_blocked_no_fp_cookie';
                ttlMs  = cfg.relayTtlMs || 14400000;
            }

            writeCache(route, reason, fpOk, ttlMs);
            // Stats record what actually loaded this visit (relay) plus the probe result.
            sendStats('relay', reason, fpOk, false, probeMs);
        });
    }

    decide();
}());
