=== Jetstrike Conflict Detector ===
Contributors: jetstrike
Tags: conflict detector, plugin conflicts, woocommerce, troubleshooting, site health
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stop deactivating plugins one by one. Jetstrike scans your site, pinpoints the conflicting pair, and shows you how to fix it.

== Description ==

**Jetstrike Conflict Detector** finds the plugin that broke your site — automatically.

Every WordPress owner knows the drill: after a plugin update something breaks, and the only way to find the culprit is to deactivate plugins one at a time until the problem goes away. For a WooCommerce store with 30+ plugins, that's hours of downtime and lost sales.

Jetstrike replaces that manual process with automated conflict detection. It analyses your plugins' code, tests combinations in an isolated sandbox, and uses a binary-search algorithm to narrow down conflicts in minutes instead of hours.

= What it detects =

* **Function and class collisions** — two plugins declaring the same function or class name
* **Hook priority conflicts** — plugins hooking the same action with incompatible priorities
* **Global variable conflicts** — plugins stepping on each other's globals
* **Script and style collisions** — duplicate enqueued handles
* **Fatal errors and PHP warnings** detected during sandbox testing
* **Performance degradation** — plugins that slow down pages significantly
* **WooCommerce-specific issues** — payment gateway conflicts, checkout field interference, HPOS compatibility, template overrides, cart calculation hooks
* **JavaScript conflicts** — global namespace pollution, jQuery overrides, prototype pollution *(Pro)*
* **Bundled library version conflicts** — when two plugins ship incompatible versions of Guzzle, Monolog, Stripe SDK, etc. *(Pro)*
* **Database-level conflicts** — colliding option keys, cron hooks, custom post type slugs, custom table names *(Pro)*

= Scan modes =

* **Quick Scan (free)** — pure static code analysis. No code is executed. Completes in under 30 seconds on a 30-plugin site.
* **Full Scan (free, 1/week — Pro: unlimited)** — static analysis plus runtime sandbox testing using isolated loopback HTTP requests.
* **Pre-Update Scan (Pro)** — simulates the effect of a pending plugin update *before* you apply it.
* **Targeted Scan (Pro)** — test one specific plugin against every other plugin on the site.

= Free vs Pro =

Free includes: Quick Scan, limited Full Scan (1/week), 3 scan history, basic health scoring, WordPress Site Health integration.

Pro adds: unlimited Full Scans, automated background scanning (WP-Cron), Pre-Update Simulation, WooCommerce deep analysis, JavaScript / dependency / database analyzers, WP-CLI integration, email alerts, the Auto-Fix Engine *(beta)*, and advanced reporting.

Agency adds: Slack notifications, REST API, multi-site network scanner, unlimited scan history, cross-site export/import, priority support.

= Site Health integration =

Jetstrike adds conflict-detection checks to WordPress's built-in Site Health screen, so conflicts surface in the same place site owners already look.

= WP-CLI =

Pro users get full CLI access for CI/CD pipelines:

`wp jetstrike scan --type=full --format=json`
`wp jetstrike conflicts --severity=critical --format=count`

Exit-code-friendly output for gating deployments.

= Privacy =

Jetstrike runs entirely on your own server. No scan data is sent to us unless you explicitly opt in to the anonymous telemetry program (which only shares plugin slugs, conflict types, and severity — never URLs, user data, or code). Telemetry can be toggled off at any time from Settings.

== Installation ==

1. Upload the `jetstrike-conflict-detector` folder to `/wp-content/plugins/`, or install via Plugins → Add New.
2. Activate the plugin through the Plugins menu.
3. Open **Jetstrike Conflict Detector** from the WordPress admin menu.
4. Click **Run Quick Scan** for an immediate static analysis.
5. (Optional) Activate a Pro licence in **Jetstrike → Settings** to unlock full scans and background monitoring.

== Frequently Asked Questions ==

= Will scanning affect my live site? =

Quick Scan is completely safe — it only reads plugin source files and runs static analysis. No plugins are activated or deactivated.

Full Scan uses isolated loopback HTTP requests to test plugin combinations. These requests are made in the background and don't affect real visitors, but on some managed hosts (e.g. when loopback requests are blocked) Full Scan may fall back to static analysis only. Jetstrike will warn you if loopback testing is unavailable.

= Is the Auto-Fix Engine safe? =

Auto-Fix ships as **opt-in BETA in v1.x**. It is disabled by default. When enabled, it writes compatibility patches to `wp-content/mu-plugins/jetstrike-patches/` that are always reversible with one click. After each patch is written, Jetstrike automatically runs a health check on your site; if the check detects a fatal error, the patch is removed and the site is restored before you even see the admin page. You can enable Auto-Fix from **Jetstrike → Settings → Auto-Fix (Beta)** once you've reviewed the warnings.

= How is this different from Health Check & Troubleshooting? =

Health Check requires you to manually enable troubleshooting mode and deactivate plugins one by one. Jetstrike automates this with a binary-search algorithm (O(N log N) rather than O(N²) tests) and can run scans in the background without any interaction.

= Does it work with WooCommerce? =

Yes. WooCommerce is the primary use case. Jetstrike includes a dedicated WooCommerce analyser that catches payment gateway conflicts, checkout field interference, cart calculation hooks, template override collisions, HPOS compatibility issues, and Checkout Block vs. Classic Checkout conflicts. It also works on any WordPress site — WooCommerce is not required.

= How many plugins can it scan? =

There is no hard limit. The binary-search algorithm handles 30+ active plugins efficiently. Very large stacks (50+ plugins) may take a few minutes for a Full Scan, which is why background monitoring is recommended for Pro users.

= Does Jetstrike send my data anywhere? =

No — not unless you opt in. All scanning happens on your server. The optional telemetry program (opt-in only) shares nothing more than plugin directory names, conflict types, and severity levels, and can be turned off at any time.

= Can I use it on a staging site first? =

Absolutely — we recommend it. Jetstrike includes an Export/Import feature (Agency) that lets you scan a staging site, export the results, and import them to production for comparison.

= Does it support multisite? =

The free and Pro tiers support a single site. The Agency tier includes a Network Scanner for multisite installations that scans every site in the network and surfaces per-site health grades.

== Screenshots ==

1. Dashboard with health score, conflict breakdown, and scan controls.
2. Scan results with severity-ranked conflict list and recommendations.
3. Compatibility Matrix — visual heat map of every plugin-to-plugin interaction.
4. Settings page with scan frequency, notifications, and licence activation.

== Changelog ==

= 1.0.0 =
* Initial release.
* Static analysis: functions, classes, globals, hooks, scripts, styles.
* Runtime sandbox with binary-search isolation.
* WooCommerce-specific analyser (payment gateways, checkout, HPOS).
* Pre-Update Simulation engine.
* Compatibility Matrix visual grid.
* Professional HTML report generator.
* Export / Import for cross-site analysis.
* Multisite Network Scanner (Agency).
* Background monitoring and plugin-update watching.
* REST API under `jetstrike/v1` namespace.
* WP-CLI command suite.
* Email and Slack notifications.
* WordPress Site Health integration.
* Admin dashboard with health scoring and scan history.
* Auto-Fix Engine (opt-in beta) with post-apply health verification and automatic rollback on failure.
* Free / Pro / Agency subscription tiers via Freemius.
* Opt-in anonymous telemetry for the conflict intelligence database.

== Upgrade Notice ==

= 1.0.0 =
First public release of Jetstrike Conflict Detector.
