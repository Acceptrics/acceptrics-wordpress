/* global jQuery, acceptricsRelayData */
(function ($) {
    'use strict';

    var cfg            = window.acceptricsRelayData || {};
    var AJAX           = cfg.ajaxUrl || '';
    var NONCE          = cfg.nonce  || '';
    var POLL_MS        = 30000; // 30 s
    var HEALTH_POLL_MS = 15000; // 15 s
    var pollTimer      = null;
    var healthTimer    = null;

    // Local mode choice (set in mode-select step, persisted to DB before token step).
    var _selectedMode = cfg.relayMode || '';

    // -------------------------------------------------------------------------
    // Step visibility
    // -------------------------------------------------------------------------

    function showStep(step) {
        $('.acpt-relay-step').hide();
        $('#acpt-step-' + step).show();
    }

    // -------------------------------------------------------------------------
    // Error helpers
    // -------------------------------------------------------------------------

    function showErr(selector, msg) { $(selector).text(msg).show(); }
    function clearErr(selector)     { $(selector).text('').hide(); }

    // -------------------------------------------------------------------------
    // Clipboard copy
    // -------------------------------------------------------------------------

    function copyText(text, $btn) {
        var origLabel = $btn.text();
        var finish = function () {
            $btn.text('Copied!').addClass('acpt-copied');
            setTimeout(function () { $btn.text(origLabel).removeClass('acpt-copied'); }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(finish).catch(function () { fallbackCopy(text, finish); });
        } else {
            fallbackCopy(text, finish);
        }
    }

    function fallbackCopy(text, cb) {
        var el = document.createElement('textarea');
        el.value = text;
        el.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(el);
        el.select();
        try { document.execCommand('copy'); } catch (e) { /* silent */ }
        document.body.removeChild(el);
        if (cb) cb();
    }

    // -------------------------------------------------------------------------
    // DNS record rendering
    // -------------------------------------------------------------------------

    function renderDnsStep(gw, cert, providerLabel, steps, relayHostname) {
        var isUnknown = (!providerLabel || providerLabel === 'Unknown provider');
        var badgeText = isUnknown
            ? 'DNS provider not detected — follow the generic steps below'
            : 'Detected DNS provider: <strong>' + escHtml(providerLabel) + '</strong>';
        var html = '<div class="acpt-provider-badge">' + badgeText + '</div><ol class="acpt-dns-steps">';
        (steps || []).forEach(function (s) { html += '<li>' + s + '</li>'; });
        html += '</ol>';
        var gwTarget = (gw && gw.value)
            ? '<code>' + escHtml(gw.value) + '</code>'
            : '<span style="background:#fff3e0;color:#e65100;font-size:11px;font-weight:600;padding:2px 7px;border-radius:4px;">available once cert is issued</span>';
        html = html.replace(/<code>__GW_TARGET__<\/code>/g, gwTarget);
        $('#acpt-dns-provider-steps').html(html);

        $('.acpt-relay-hostname-display').text(relayHostname || '');

        if (gw && gw.name) {
            $('#acpt-record-gw-name').text(gw.name);
            $('#acpt-copy-gw-name').data('copy', gw.name);
        }
        if (gw && gw.value) {
            $('#acpt-record-gw-val').text(gw.value);
            $('#acpt-copy-gw-val').data('copy', gw.value);
        }
        $('#acpt-record-cert-name').text(cert.name  || '');
        $('#acpt-record-cert-val').text(cert.value  || '');
        $('#acpt-copy-cert-name').data('copy', cert.name  || '');
        $('#acpt-copy-cert-val').data('copy',  cert.value || '');
    }

    // -------------------------------------------------------------------------
    // Propagation status rows
    // -------------------------------------------------------------------------

    function setStatusRow(id, ready) {
        var $row = $('#' + id);
        $row.find('.acpt-check').toggle(ready);
        $row.find('.acpt-spin').toggle(!ready);
        $row.toggleClass('acpt-row-done', ready).toggleClass('acpt-row-pending', !ready);
    }

    function updatePropStatus(certReady, cnameReady) {
        setStatusRow('acpt-status-cert',  certReady);
        setStatusRow('acpt-status-cname', cnameReady);
        if (certReady && cnameReady) {
            $('#acpt-btn-check-now').hide();
            $('#acpt-poll-note').text('Both records verified — activating relay…');
        }
    }

    // -------------------------------------------------------------------------
    // DNS action buttons
    // -------------------------------------------------------------------------

    var _zoneFileContent = '';

    function renderDnsActions(deeplink, importSupported, zoneFile) {
        _zoneFileContent = zoneFile || '';
        if (deeplink) {
            $('#acpt-btn-open-dns').attr('href', deeplink).show();
        } else {
            $('#acpt-btn-open-dns').hide();
        }
        if (_zoneFileContent) {
            $('#acpt-btn-download-zone').show();
            $('#acpt-import-hint').toggle(!!importSupported);
        } else {
            $('#acpt-btn-download-zone').hide();
            $('#acpt-import-hint').hide();
        }
    }

    // -------------------------------------------------------------------------
    // Polling
    // -------------------------------------------------------------------------

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    function startPolling() {
        stopPolling();
        doPoll();
        pollTimer = setInterval(doPoll, POLL_MS);
    }

    function doPoll() {
        $.post(AJAX, { action: 'acceptrics_check_relay_status', nonce: NONCE }, function (r) {
            if (!r.success) return;
            var d = r.data;
            updatePropStatus(d.certReady, d.cnameReady);

            if (d.zoneFile) {
                renderDnsActions(cfg.providerDeeplink || '', d.importSupported, d.zoneFile);
            }

            if (d.status === 'active') {
                stopPolling();
                window.location.reload();
            } else if (d.status === 'failed') {
                stopPolling();
                showStep('failed');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Health badge (path mode only)
    // -------------------------------------------------------------------------

    function stopHealthPolling() {
        if (healthTimer) { clearInterval(healthTimer); healthTimer = null; }
    }

    function startHealthPolling() {
        stopHealthPolling();
        fetchHealth();
        healthTimer = setInterval(fetchHealth, HEALTH_POLL_MS);
    }

    function fetchHealth() {
        $.post(AJAX, { action: 'acceptrics_get_relay_health', nonce: NONCE }, function (r) {
            if (!r.success) return;
            var d = r.data;
            var $badge = $('#acpt-health-badge');
            var $label = $('#acpt-health-label');
            var $stats = $('#acpt-health-stats');
            if (d.degraded) {
                $badge.removeClass('healthy unknown').addClass('degraded');
                $label.text('Fallback active — routing via Google directly');
            } else {
                $badge.removeClass('degraded unknown').addClass('healthy');
                $label.text('Healthy — routing via your server');
            }
            if (d.stats && d.stats.count && d.stats.p50 !== false) {
                var avg  = parseInt(d.stats.p50, 10);
                var peak = parseInt(d.stats.p95, 10);
                var speed = avg < 300 ? 'fast' : avg < 600 ? 'moderate' : 'slow';
                $stats.text('avg ' + avg + 'ms · peak ' + peak + 'ms · ' + speed);
            } else {
                $stats.text('');
            }
        });
    }

    function escHtml(s) { return $('<div>').text(s || '').html(); }

    var TAG_RE = /^(G-|GTM-|AW-|DC-|UA-|GT-|GMC-)[A-Z0-9]+$/i;

    // -------------------------------------------------------------------------
    // Tag pill rendering (provision step)
    // -------------------------------------------------------------------------

    var _provisionTags = []; // array of tag ID strings

    function renderProvisionTags() {
        var $list = $('#acpt-provision-tags').empty();
        _provisionTags.forEach(function (tid, i) {
            var isPrimary = (i === 0);
            var pill = $('<span class="acpt-tag-pill' + (isPrimary ? ' acpt-tag-primary' : '') + '">')
                .text(tid);
            if (isPrimary) {
                pill.append('<span style="font-size:10px;opacity:0.6;margin-left:4px;">primary</span>');
            } else {
                var rm = $('<button type="button" class="acpt-tag-pill-remove" title="Remove">&times;</button>');
                rm.on('click', function () {
                    _provisionTags = _provisionTags.filter(function (t) { return t !== tid; });
                    renderProvisionTags();
                });
                pill.append(rm);
            }
            $list.append(pill);
        });
    }

    // -------------------------------------------------------------------------
    // Active tag pill rendering
    // -------------------------------------------------------------------------

    function renderActiveTags(tags, primaryTag) {
        var $list = $('#acpt-active-tags').empty();
        tags.forEach(function (tid) {
            var isPrimary = (tid === primaryTag);
            var pill = $('<span class="acpt-tag-pill' + (isPrimary ? ' acpt-tag-primary' : '') + '" data-tag="' + escHtml(tid) + '">')
                .text(tid);
            if (isPrimary) {
                pill.append('<span style="font-size:10px;opacity:0.6;margin-left:4px;">primary</span>');
            }
            var rm = $('<button type="button" class="acpt-tag-pill-remove" data-tag="' + escHtml(tid) + '" title="Remove">&times;</button>');
            rm.on('click', function () { removeActiveTag(tid); });
            pill.append(rm);
            $list.append(pill);
        });
    }

    function removeActiveTag(tagId) {
        $.post(AJAX, { action: 'acceptrics_patch_relay_tags', nonce: NONCE, action_type: 'remove', tag_id: tagId })
            .done(function (r) {
                if (r.success) {
                    if (r.data.primaryTag) { cfg.tagId = r.data.primaryTag; }
                    renderActiveTags(r.data.tags, cfg.tagId);
                } else {
                    showErr('#acpt-tags-error', r.data || 'Failed to remove tag.');
                }
            })
            .fail(function () { showErr('#acpt-tags-error', 'Network error.'); });
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    $(function () {
        var state = cfg.state || 'mode-select';
        showStep(state);

        // ======================================================================
        // Health polling — start immediately if already in active path mode
        // ======================================================================
        if (state === 'active' && cfg.relayMode === 'path') {
            startHealthPolling();
        }

        // ======================================================================
        // Step 0 — Mode selection
        // ======================================================================
        $('.acpt-mode-card').on('click', function () {
            $('.acpt-mode-card').removeClass('acpt-mode-selected');
            $(this).addClass('acpt-mode-selected');
            _selectedMode = $(this).data('mode');
            $('#acpt-btn-choose-mode').prop('disabled', false);
            // Show billing note when DNS mode is chosen; hide for path mode.
            $('#acpt-dns-billing-note').toggle(_selectedMode === 'dns');
        });

        $('#acpt-btn-choose-mode').on('click', function () {
            if (!_selectedMode) {
                showErr('#acpt-mode-error', 'Please choose a relay type.');
                return;
            }
            clearErr('#acpt-mode-error');
            var $b = $(this).prop('disabled', true).text('Saving…');
            $.post(AJAX, { action: 'acceptrics_set_relay_mode', nonce: NONCE, mode: _selectedMode })
                .done(function (r) {
                    $b.prop('disabled', false).text('Continue');
                    if (r.success) {
                        cfg.relayMode = _selectedMode;
                        // Path mode skips the token step entirely.
                        showStep(_selectedMode === 'path' ? 'configure-path' : 'token');
                    } else {
                        showErr('#acpt-mode-error', r.data || 'Could not save mode. Please try again.');
                    }
                })
                .fail(function () {
                    $b.prop('disabled', false).text('Continue');
                    showErr('#acpt-mode-error', 'Network error. Please try again.');
                });
        });

        if (state === 'dns') {
            renderDnsStep(
                cfg.gatewayCname  || {},
                cfg.certCname     || {},
                cfg.providerLabel || 'Unknown provider',
                cfg.providerSteps || [],
                cfg.relayHostname || ''
            );
            renderDnsActions(cfg.providerDeeplink || '', cfg.importSupported, cfg.zoneFile || '');

            if (cfg.pollingStarted) {
                updatePropStatus(cfg.certReady, cfg.cnameReady);
                $('#acpt-btn-records-added').hide();
                $('#acpt-polling-section').show();
                startPolling();
            }
        }

        if (state === 'active') {
            // Health polling is started in the block at the top of $(function) above.
            $('.acpt-relay-hostname-display').text(cfg.relayHostname || '');
            if (cfg.relayHostname){ $('#acpt-detail-hostname').text(cfg.relayHostname); }
            if (cfg.tagIds && cfg.tagIds.length) {
                renderActiveTags(cfg.tagIds, cfg.tagId);
            }
        }

        // ==================================================================
        // Step 1 — Connect token
        // ==================================================================
        $('#acpt-btn-connect-token').on('click', function () {
            var token = $('#acpt-token-input').val().trim();
            if (!token) { showErr('#acpt-token-error', 'Please paste your API token.'); return; }
            clearErr('#acpt-token-error');
            var $b = $(this).prop('disabled', true).text('Connecting…');
            $.post(AJAX, { action: 'acceptrics_save_token', nonce: NONCE, token: token })
                .done(function (r) {
                    $b.prop('disabled', false).text('Connect');
                    if (r.success) {
                        // Navigate to the right configure step based on chosen mode.
                        var mode = _selectedMode || cfg.relayMode || 'dns';
                        showStep(mode === 'path' ? 'configure-path' : 'configure');
                    } else {
                        showErr('#acpt-token-error', r.data || 'Connection failed. Please try again.');
                    }
                })
                .fail(function () {
                    $b.prop('disabled', false).text('Connect');
                    showErr('#acpt-token-error', 'Network error. Please try again.');
                });
        });

        // ==================================================================
        // Step 2 (path) — Path relay tag management + provisioning
        // ==================================================================
        var _pathTags = [];

        function renderPathTags() {
            var $list = $('#acpt-path-provision-tags').empty();
            _pathTags.forEach(function (tid, i) {
                var isPrimary = (i === 0);
                var pill = $('<span class="acpt-tag-pill' + (isPrimary ? ' acpt-tag-primary' : '') + '">').text(tid);
                if (isPrimary) {
                    pill.append('<span style="font-size:10px;opacity:0.6;margin-left:4px;">primary</span>');
                } else {
                    var rm = $('<button type="button" class="acpt-tag-pill-remove" title="Remove">&times;</button>');
                    rm.on('click', function () {
                        _pathTags = _pathTags.filter(function (t) { return t !== tid; });
                        renderPathTags();
                    });
                    pill.append(rm);
                }
                $list.append(pill);
            });
        }

        function addPathTag() {
            var val = $('#acpt-path-tag-id-input').val().trim().toUpperCase();
            if (!val) return;
            if (!TAG_RE.test(val)) {
                showErr('#acpt-path-provision-error', 'Invalid format. Expected G-, GTM-, AW-, DC-, etc.');
                return;
            }
            if (_pathTags.indexOf(val) !== -1) { $('#acpt-path-tag-id-input').val(''); return; }
            clearErr('#acpt-path-provision-error');
            _pathTags.push(val);
            renderPathTags();
            $('#acpt-path-tag-id-input').val('').focus();
        }

        $('#acpt-btn-path-add-tag').on('click', addPathTag);
        $('#acpt-path-tag-id-input').on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addPathTag(); }
        });

        // Live preview of the path input — strip all slashes so only a single
        // path segment can be entered (prevents metrics/healthy-style mistakes).
        $('#acpt-metrics-path-input').on('input', function () {
            var raw = $(this).val().replace(/[^a-zA-Z0-9\-_]/g, '').toLowerCase() || 'metrics';
            $('#acpt-path-preview').text(raw);
        });

        $('#acpt-btn-provision-path').on('click', function () {
            // Accept typed tag that wasn't explicitly added.
            var typed = $('#acpt-path-tag-id-input').val().trim().toUpperCase();
            if (typed && TAG_RE.test(typed) && _pathTags.indexOf(typed) === -1) {
                _pathTags.push(typed);
                renderPathTags();
                $('#acpt-path-tag-id-input').val('');
            }

            if (!_pathTags.length) {
                showErr('#acpt-path-provision-error', 'Please enter at least one Google Tag ID.');
                return;
            }

            var metricsPath = $('#acpt-metrics-path-input').val().replace(/[^a-zA-Z0-9\-_]/g, '').toLowerCase() || 'metrics';
            var threshold   = parseInt($('#acpt-threshold-input').val(), 10) || 800;
            clearErr('#acpt-path-provision-error');
            var $b = $(this).prop('disabled', true).text('Activating…');

            var postData = {
                action: 'acceptrics_provision_path_relay', nonce: NONCE,
                tag_id: _pathTags[0], metrics_path: metricsPath, threshold_ms: threshold,
            };
            _pathTags.slice(1).forEach(function (tid, i) {
                postData['extra_tag_ids[' + i + ']'] = tid;
            });

            $.post(AJAX, postData)
                .done(function (r) {
                    $b.prop('disabled', false).text('Activate Path Relay');
                    if (r.success) {
                        window.location.reload();
                    } else {
                        showErr('#acpt-path-provision-error', r.data || 'Setup failed. Please try again.');
                    }
                })
                .fail(function () {
                    $b.prop('disabled', false).text('Activate Path Relay');
                    showErr('#acpt-path-provision-error', 'Network error. Please try again.');
                });
        });

        // ==================================================================
        // Step 2 — Add tag button (configure step)
        // ==================================================================
        function addProvisionTag() {
            var val = $('#acpt-tag-id-input').val().trim().toUpperCase();
            if (!val) return;
            if (!TAG_RE.test(val)) {
                showErr('#acpt-provision-error', 'Invalid format. Expected G-, GTM-, AW-, DC-, etc.');
                return;
            }
            if (_provisionTags.indexOf(val) !== -1) {
                $('#acpt-tag-id-input').val('');
                return;
            }
            clearErr('#acpt-provision-error');
            _provisionTags.push(val);
            renderProvisionTags();
            $('#acpt-tag-id-input').val('').focus();
        }

        $('#acpt-btn-add-tag').on('click', addProvisionTag);
        $('#acpt-tag-id-input').on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addProvisionTag(); }
        });

        // ==================================================================
        // Step 2 — Provision relay
        // ==================================================================
        $('#acpt-btn-provision').on('click', function () {
            // Allow typing a tag directly without hitting Add first
            var typed = $('#acpt-tag-id-input').val().trim().toUpperCase();
            if (typed && TAG_RE.test(typed) && _provisionTags.indexOf(typed) === -1) {
                _provisionTags.push(typed);
                renderProvisionTags();
                $('#acpt-tag-id-input').val('');
            }

            if (!_provisionTags.length) {
                showErr('#acpt-provision-error', 'Please enter at least one Google Tag ID.');
                return;
            }
            var primaryTag = _provisionTags[0];
            var subdomain  = ($('#acpt-subdomain-input').val().trim().toLowerCase() || 't')
                             .replace(/[^a-z0-9\-]/g, '');
            clearErr('#acpt-provision-error');
            var $b = $(this).prop('disabled', true).text('Setting up…');

            // Build post data with extra tags
            var postData = {
                action: 'acceptrics_provision_relay', nonce: NONCE,
                tag_id: primaryTag, subdomain: subdomain,
            };
            _provisionTags.slice(1).forEach(function (tid, i) {
                postData['extra_tag_ids[' + i + ']'] = tid;
            });

            $.post(AJAX, postData)
                .done(function (r) {
                    $b.prop('disabled', false).text('Set up Relay');
                    if (r.success) {
                        var d = r.data;
                        renderDnsStep(d.gatewayCname, d.certCname, d.instructions.label, d.instructions.steps, d.relayHostname);
                        renderDnsActions(d.providerDeeplink || '', d.importSupported || false, d.zoneFile || '');
                        showStep('dns');
                    } else {
                        var errData = r.data;
                        if (errData && errData.code === 'relay_billing_required') {
                            $('#acpt-provision-error')
                                .html('CNAME Relay is a paid add-on ($5/month). <a href="https://acceptrics.com/account" target="_blank" rel="noopener">Enable it at acceptrics.com/account</a> under <strong>Add-ons &rarr; CNAME Relay</strong>, then return here.')
                                .show();
                        } else {
                            showErr('#acpt-provision-error', (errData && errData.message) || errData || 'Setup failed. Please try again.');
                        }
                    }
                })
                .fail(function () {
                    $b.prop('disabled', false).text('Set up Relay');
                    showErr('#acpt-provision-error', 'Network error. Please try again.');
                });
        });

        // ==================================================================
        // "I've added both records" → start polling
        // ==================================================================
        $('#acpt-btn-records-added').on('click', function () {
            $(this).prop('disabled', true).text('Checking…');
            $.post(AJAX, { action: 'acceptrics_mark_records_added', nonce: NONCE });
            setTimeout(function () {
                $('#acpt-btn-records-added').hide();
                $('#acpt-polling-section').show();
                startPolling();
            }, 400);
        });

        // ==================================================================
        // Manual check
        // ==================================================================
        $('#acpt-btn-check-now').on('click', function () {
            var $b = $(this).prop('disabled', true).text('Checking…');
            doPoll();
            setTimeout(function () { $b.prop('disabled', false).text('Check now'); }, 3000);
        });

        // ==================================================================
        // Zone file download
        // ==================================================================
        $('#acpt-btn-download-zone').on('click', function () {
            if (!_zoneFileContent) return;
            var hostname = (cfg.relayHostname || 'relay').replace(/\./g, '_');
            var filename = 'acceptrics-relay-' + hostname + '.txt';
            var blob = new Blob([_zoneFileContent], { type: 'text/plain;charset=utf-8' });
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href = url; a.download = filename; a.style.display = 'none';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });

        // ==================================================================
        // Back-navigation links (data-goto="step-name")
        // ==================================================================
        $(document).on('click', '.acpt-back-link', function () {
            var target = $(this).data('goto');
            if (!target) return;
            // Re-highlight the previously chosen mode card when returning to mode-select.
            if (target === 'mode-select' && _selectedMode) {
                $('.acpt-mode-card').removeClass('acpt-mode-selected');
                $('.acpt-mode-card[data-mode="' + _selectedMode + '"]').addClass('acpt-mode-selected');
                $('#acpt-btn-choose-mode').prop('disabled', false);
            }
            showStep(target);
        });

        // ==================================================================
        // Copy buttons
        // ==================================================================
        $(document).on('click', '.acpt-copy-btn', function () {
            var val = $(this).data('copy');
            if (val) copyText(val, $(this));
        });

        // ==================================================================
        // Disconnect
        // ==================================================================
        $(document).on('click', '.acpt-btn-disconnect', function () {
            var _mode = (cfg && cfg.relayMode) || _selectedMode || 'dns';
            var _msg  = _mode === 'path'
                ? 'Disable Analytics Recovery? Tag traffic will stop routing through your server.'
                : 'Disconnect? This will remove the relay subdomain and disconnect your API token.';
            if (!confirm(_msg)) return;
            var $btn = $(this).prop('disabled', true).text('Disconnecting…');
            $.post(AJAX, { action: 'acceptrics_disconnect_relay', nonce: NONCE })
                .done(function (r) {
                    if (r.success) {
                        stopPolling();
                        stopHealthPolling();
                        window.location.reload();
                    } else {
                        $btn.prop('disabled', false).text('Disconnect relay');
                    }
                })
                .fail(function () {
                    $btn.prop('disabled', false).text('Disconnect relay');
                });
        });

        // ==================================================================
        // Retry from failed state
        // ==================================================================
        $('#acpt-btn-retry').on('click', function () {
            var mode = cfg.relayMode || _selectedMode || 'dns';
            showStep(mode === 'path' ? 'configure-path' : 'configure');
        });

        // ==================================================================
        // Active — test relay connection
        // ==================================================================
        $('#acpt-btn-test-relay').on('click', function () {
            var hostname = cfg.relayHostname || $('#acpt-detail-hostname').text().trim();
            if (!hostname) return;

            var $btn    = $(this).prop('disabled', true).text('Testing…');
            var $result = $('#acpt-test-result').hide();
            var mode    = cfg.relayMode || 'dns';

            function pass(msg) {
                $result.css({ color: '#2e7d32', background: '#e8f5e9', padding: '4px 10px', borderRadius: '4px' }).text(msg).show();
            }
            function failMsg(msg) {
                $result.css({ color: '#c62828', background: '#fce4ec', padding: '4px 10px', borderRadius: '4px' }).text(msg).show();
            }

            // Probe /healthy: it round-trips through the relay to Google's gateway
            // (fps.goog) and — unlike /gtag/js — its URL doesn't match ad-blocker
            // filter patterns, so the admin's own blocker can't fail the test.
            var base = hostname.replace(/\/+$/, '');
            var bust = '?_t=' + Date.now();

            if (mode === 'path') {
                // The relay path lives on this same site by definition — build the
                // probe from the browser's own origin (the stored hostname omits
                // non-standard ports and would break dev/staging sites).
                var relayPath = base.indexOf('/') !== -1
                    ? base.slice(base.indexOf('/') + 1)
                    : (cfg.metricsPath || 'metrics');
                var healthUrl = window.location.origin + '/' + relayPath + '/healthy' + bust;
                fetch(healthUrl, { method: 'GET', cache: 'no-store' })
                    .then(function (r) {
                        if (r.ok) {
                            pass('✓ Relay is responding — round trip to Google\u2019s gateway succeeded');
                        } else {
                            failMsg('✗ Relay path reached but returned HTTP ' + r.status + ' — a caching or security plugin may be intercepting it');
                        }
                    })
                    .catch(function () {
                        failMsg('✗ Could not reach the relay path — check for caching or security plugins intercepting it');
                    })
                    .finally(function () {
                        $btn.prop('disabled', false).text('Test relay connection');
                    });
            } else {
                // Cross-origin subdomain: response is opaque, but a resolved fetch
                // proves DNS resolves and the certificate is valid.
                fetch('https://' + base + '/healthy' + bust, { method: 'GET', mode: 'no-cors', cache: 'no-store' })
                    .then(function () {
                        pass('✓ Relay is reachable — DNS and SSL certificate are working');
                    })
                    .catch(function () {
                        failMsg('✗ Could not reach relay — DNS may still be propagating or the SSL certificate is still issuing');
                    })
                    .finally(function () {
                        $btn.prop('disabled', false).text('Test relay connection');
                    });
            }
        });

        // ==================================================================
        // Active — add tag to existing relay
        // ==================================================================
        function addActiveTag() {
            var val = $('#acpt-new-tag-input').val().trim().toUpperCase();
            if (!val) return;
            if (!TAG_RE.test(val)) {
                showErr('#acpt-tags-error', 'Invalid format. Expected G-, GTM-, AW-, DC-, etc.');
                return;
            }
            clearErr('#acpt-tags-error');
            var $btn = $('#acpt-btn-add-active-tag').prop('disabled', true).text('Adding…');
            $.post(AJAX, { action: 'acceptrics_patch_relay_tags', nonce: NONCE, action_type: 'add', tag_id: val })
                .done(function (r) {
                    if (r.success) {
                        $('#acpt-new-tag-input').val('');
                        renderActiveTags(r.data.tags, cfg.tagId);
                    } else {
                        showErr('#acpt-tags-error', r.data || 'Failed to add tag.');
                    }
                })
                .fail(function () { showErr('#acpt-tags-error', 'Network error.'); })
                .always(function () { $btn.prop('disabled', false).text('Add tag'); });
        }

        $('#acpt-btn-add-active-tag').on('click', addActiveTag);
        $('#acpt-new-tag-input').on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addActiveTag(); }
        });

        // ==================================================================
        // Active — routing strategy selector
        // ==================================================================
        $('#acpt-strategy-options').on('change', 'input[name="acpt_relay_strategy"]', function () {
            var strategy = $(this).val();
            $('#acpt-strategy-options .acpt-strategy-label').each(function () {
                var sel = $(this).data('strategy') === strategy;
                $(this).css({
                    'border-color': sel ? '#4CAF50' : '#e0e0e0',
                    'background':   sel ? '#f1fbf1' : '#fff',
                });
            });
        });

        $('#acpt-btn-save-strategy').on('click', function () {
            var strategy = $('input[name="acpt_relay_strategy"]:checked').val();
            if (!strategy) return;
            var $btn    = $(this).prop('disabled', true).text('Saving…');
            var $result = $('#acpt-strategy-result').hide();
            $.post(AJAX, { action: 'acceptrics_save_relay_strategy', nonce: NONCE, strategy: strategy })
                .done(function (r) {
                    if (r.success) {
                        $result.css({ color: '#2e7d32' }).text('Saved.').show();
                    } else {
                        $result.css({ color: '#c62828' }).text(r.data || 'Error saving strategy.').show();
                    }
                })
                .fail(function () { $result.css({ color: '#c62828' }).text('Network error.').show(); })
                .always(function () {
                    $btn.prop('disabled', false).text('Save strategy');
                    setTimeout(function () { $result.fadeOut(); }, 3000);
                });
        });
    });

})(jQuery);
