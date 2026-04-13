=== Jetstrike Conflict Detector ===
Contributors: jetstrike
Tags: conflict detector, plugin conflicts, woocommerce, troubleshooting, site health
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Intelligent plugin conflict detection for WordPress and WooCommerce. Automatically scans, detects, and reports plugin conflicts before they break your store.

== Description ==

**Jetstrike Conflict Detector** is the most advanced plugin conflict detection tool for WordPress. It proactively scans your site for plugin conflicts using static code analysis, runtime sandbox testing, and WooCommerce-specific intelligence — so you catch problems before they crash your store.

= Why Jetstrike? =

Every WordPress site owner has experienced the pain of plugin conflicts — the white screen of death after an update, broken checkout flows, or mysterious errors that take hours to diagnose. Existing tools require you to manually deactivate plugins one by one. Jetstrike does it all automatically.

= Key Features =

**Static Code Analysis**
* Function and class name collision detection
* WordPress hook/filter conflict analysis
* Global variable conflict detection
* Script/style handle collision detection
* Shortcode and REST API namespace conflicts

**Runtime Sandbox Testing**
* Isolated loopback testing — never affects your live site
* Binary search algorithm isolates conflicts in O(N log N) tests
* Detects fatal errors, HTTP 500s, and PHP warnings
* Performance degradation detection

**WooCommerce Intelligence (Pro)**
* Payment gateway conflict detection
* Checkout field manipulation conflicts
* Cart calculation interference
* Template override collisions
* HPOS (High-Performance Order Storage) compatibility checks

**Background Monitoring (Pro)**
* Automated scheduled scans via WP-Cron
* Plugin update detection and re-scanning
* New plugin activation scanning
* Continuous site health scoring

**Notifications (Pro/Agency)**
* Email alerts for new conflicts
* Slack webhook integration (Agency)
* WordPress admin notice alerts

**Full REST API (Agency)**
* Programmatic scan control
* Conflict management
* Multi-site support

= Site Health Integration =

Jetstrike integrates with WordPress Site Health, adding conflict detection to your site's built-in health checks.

== Installation ==

1. Upload the `jetstrike-conflict-detector` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Conflict Detector** in your admin menu
4. Run your first Quick Scan

== Frequently Asked Questions ==

= Will scanning affect my live site? =

No. Quick Scans use static code analysis only — no code is executed. Full Scans use isolated loopback requests that don't affect your visitors.

= How is this different from Health Check & Troubleshooting? =

Health Check requires you to manually enable troubleshooting mode and deactivate plugins one by one. Jetstrike automates the entire process with intelligent binary search and runs scans in the background without any manual intervention.

= Does it work with WooCommerce? =

Yes! Jetstrike has WooCommerce-specific analysis that detects payment gateway conflicts, checkout field interference, cart calculation issues, template override collisions, and HPOS compatibility problems.

= How many plugins can it scan? =

There's no limit. The binary search algorithm efficiently handles 30+ active plugins, reducing the number of tests needed from hundreds to dozens.

== Changelog ==

= 1.0.0 =
* Initial release
* Static code analysis (functions, classes, globals, hooks, resources)
* Runtime sandbox testing with binary search
* WooCommerce-specific conflict analysis
* Background monitoring and update watching
* REST API
* Email and Slack notifications
* WordPress Site Health integration
* Admin dashboard with health scoring
* Free/Pro/Agency subscription tiers

== Upgrade Notice ==

= 1.0.0 =
Initial release of Jetstrike Conflict Detector.
