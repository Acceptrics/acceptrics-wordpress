=== Acceptrics Consent Banner ===
Contributors: Acceptrics
Donate link: https://acceptrics.com
Tags: consent, banner, cookie, GDPR, analytics, ad blocker, Google Tag Gateway
Requires at least: 5.9
Tested up to: 7.0
Stable tag: 2.10
Requires PHP: 7.4
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

GDPR-compliant consent banner with built-in analytics recovery — get back data lost to ad blockers without any DNS changes.

== Description ==

Acceptrics is a consent management platform that recovers analytics and conversion data lost to ad blockers and browser privacy restrictions — without any DNS changes to get started.

**The problem:** 25–40% of your visitors use ad blockers or browsers that block third-party tracking requests by default (Safari ITP, Brave, Firefox Enhanced Tracking Protection). That means up to 40% of your Google Analytics events, conversion signals, and remarketing data never reaches Google.

**The solution:** Acceptrics routes your Google tags through your own domain, making them indistinguishable from your site's own traffic. Ad blockers can't block what looks like a first-party request.

**Zero DNS changes required to start.** Enable one toggle in the plugin settings and your analytics recovery activates immediately. No subdomain setup, no certificate, no waiting.

= Features =

* **Analytics recovery** — route Google tags through your WordPress server to recover data blocked by ad blockers and browsers
* **Zero DNS setup** — activate immediately with the Server Path mode; no DNS records or certificate required
* **Automatic fallback** — if your server is slow, the plugin falls back to direct Google endpoints and retries automatically
* **Blocker detection** — samples 10% of page loads to estimate what percentage of your visitors have ad blockers, and shows an uplift estimate in the report tab
* **Consent banner** — fully GDPR and ePrivacy compliant consent banner via your Acceptrics account
* **WP Consent API** — consent signals propagate to other plugins automatically
* **Google Consent Mode v2** — certified Google CMP Partner integration included
* **DNS Subdomain mode** — upgrade path to a CDN-backed subdomain for maximum performance and consent enforcement at the network layer

= How analytics recovery works =

When a visitor loads your site, their browser fires your Google Analytics or Google Ads tags. Ad blockers intercept these requests before they leave the browser.

With Acceptrics analytics recovery enabled, those same tags load from your own domain (e.g. `yourdomain.com/metrics/gtag/js`). The browser sees a first-party request — your own site — and lets it through.

**Server Path mode** (zero DNS changes):
Your WordPress server receives the tag request, forwards it to Google's measurement endpoint (fps.goog), and returns the response. Activates instantly.

**DNS Subdomain mode** (upgrade path):
A CDN-backed subdomain (`t.yourdomain.com`) handles tag traffic at the edge for maximum performance. Enables consent enforcement — tags rejected by the visitor are blocked at the network layer before they can fire. Requires two DNS records.

= Settings and Report tabs =

The plugin now has two sections:

* **Settings** — configure your account code, consent banner, and analytics recovery mode
* **Analytics Recovery** — view your blocker detection data: what percentage of visitors have blockers, estimated events recovered per day, and a 7-day breakdown

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/acceptrics-consent-banner` directory, or install through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings → Acceptrics Consent Banner and enter your account code from acceptrics.com/wizard.
4. To enable analytics recovery: in the same settings page, expand the Analytics Recovery card and choose "Server Path" (zero DNS changes) to activate immediately.

== Frequently Asked Questions ==

= What is analytics recovery? =

Ad blockers and privacy-focused browsers (Safari, Brave, Firefox) block requests to third-party domains like googletagmanager.com. Analytics recovery routes your Google tags through your own domain so they reach Google's measurement servers. You typically see a 15–30% increase in recorded analytics events within 24 hours of enabling it.

= Do I need to change my DNS? =

No. The Server Path mode requires no DNS changes at all — it activates immediately when you click "Start Analytics Recovery" in the plugin settings. The DNS Subdomain mode is an optional upgrade for better performance and consent enforcement at the network layer.

= What is the performance impact? =

Server Path mode adds a small amount of load to your WordPress server for each tag request (typically 50–200ms round-trip to Google's servers). The plugin monitors response times automatically: if your server median response time exceeds 800ms, it falls back to direct Google endpoints for 60 seconds and then retries. The fallback threshold is configurable.

Blocker detection adds ~1ms on the 10% of page loads that are sampled. It has no impact on the other 90%.

= Where do I get my account code? =

Sign up at https://acceptrics.com/wizard. Your account code is shown after registration and in your Acceptrics dashboard.

= How do I customize the banner appearance? =

All banner customization (colors, text, layout, regional targeting) is managed from your Acceptrics dashboard. Changes go live instantly — no plugin update needed.

= Does this plugin support WP Consent API? =

Yes. When the WP Consent API plugin is installed, Acceptrics consent decisions are automatically forwarded so other plugins (e.g. analytics, marketing) respect the user's choices.

= Does this plugin support Google Consent Mode? =

Yes. Acceptrics is a certified Google CMP Partner and supports Google Consent Mode v2 out of the box.

= What is the GeoIP Detect plugin for? =

When analytics recovery is active in Server Path mode, installing the free GeoIP Detect plugin allows Acceptrics to forward accurate visitor location headers to Google. Without it, Google uses only the visitor's IP address for geo-targeting, which is less accurate. It's optional — analytics recovery works without it.

== External services ==

This plugin loads your consent banner from the Acceptrics CDN:

* `https://acct.acceptrics.com/{your-account-code}` — account-specific banner script injected into every page

When analytics recovery is enabled, this plugin forwards tag requests from your visitors to Google's measurement endpoint:

* `https://{tag-id}.fps.goog` — Google's first-party measurement endpoint (fps.goog)

The forwarded request includes: the visitor's IP address, User-Agent, Accept-Language, Referer, and analytics cookies (_ga, _gid, _gcl, etc.). WordPress authentication cookies are never forwarded. See Google's privacy policy for how fps.goog handles this data.

When blocker detection is enabled, this plugin fires a test request from each sampled visitor's browser:

* `https://www.googletagmanager.com` — to check if the visitor's browser can reach Google directly

No personally identifiable information (PII) is shared with Acceptrics by this plugin. Consent decisions are stored in the visitor's browser (localStorage) and processed locally.

Service provider: Acceptrics LLC
Terms of Service: https://acceptrics.com/assets/terms.pdf
Privacy Policy: https://acceptrics.com/privacy

== Changelog ==

= 2.10 =
* New Status card: see at a glance whether the banner is live (and for which visitors), plus Consent Mode, WP Consent API sync, and Tag Relay state
* Settings UX: WP Consent API install and sync merged into one Integrations row; clearer relay setup steps and tag-ID help; relay connection test now probes the blocker-safe /healthy endpoint end to end
* Analytics Report tab clearly scoped: it covers analytics recovery, not the consent banner
* Optional anonymous usage data (opt-in): product events only — activation, setup steps, WordPress/PHP version, site domain — never anything about your visitors. Off unless you allow it; change anytime under Integrations
* Create your Acceptrics account without leaving WordPress: enter your email on the settings page, pick where the banner should appear (EU/EEA or worldwide), and the plugin creates the account, saves your banner code, and enables the banner automatically
* Existing accounts are never overwritten; if the email is already registered, the plugin points you to your welcome email or account login
* Your banner code is still emailed to you as a permanent record

= 2.9 =
* Added Analytics Recovery tab in the admin UI — shows blocker detection data, uplift estimate, and 7-day breakdown
* Added blocker detection: samples 10% of page loads to estimate what percentage of visitors have ad blockers (opt-in, configurable)
* Reframed all customer-facing copy around "analytics recovery" — no DNS changes required to start
* Split plugin admin into Settings and Analytics Recovery tabs

= 2.8 =
* Server Path analytics recovery: route Google tags through your WordPress server with zero DNS changes
* Automatic health monitoring: falls back to direct Google endpoints if server median response time exceeds threshold (800ms default), retries after 60 seconds
* GeoIP Detect integration: forwards accurate visitor location headers to Google when the plugin is installed
* Visitor IP resolution: correctly identifies the real visitor IP behind Cloudflare, load balancers, and other reverse proxies
* Privacy improvement: WordPress authentication cookies are filtered and never forwarded to Google's measurement endpoint
* Admin UI: geoip-detect install button in the analytics recovery setup flow

= 2.0 =
* Simplified setup: enter your Acceptrics account code and the banner is ready — no other configuration required
* Account-specific script (`acct.acceptrics.com/{accountId}`) now injected directly into `<head>` of every page
* Redesigned admin UI in the Acceptrics brand theme
* WP Consent API integration preserved: syncs analytics and ads consent automatically
* Removed hardcoded banner configuration — all customization now lives in the Acceptrics dashboard
* Updated minimum WordPress requirement to 5.9
* Requires PHP 7.4+

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 2.10 =
No Acceptrics account yet? You can now create one directly from Settings → Acceptrics Consent Banner — banner live in under a minute.

= 2.9 =
New Analytics Recovery tab with blocker detection. Enable in Settings → Acceptrics Consent Banner → Status → "Enable blocker detection" to start measuring your ad blocker exposure.

== License ==

Acceptrics Consent Banner is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

Acceptrics Consent Banner is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with Acceptrics Consent Banner. If not, see http://www.gnu.org/licenses/gpl-2.0.txt.
