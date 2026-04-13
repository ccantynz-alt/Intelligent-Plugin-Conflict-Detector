# Intelligent Plugin Conflict Detector

A WordPress plugin that intelligently detects plugin conflicts, tests plugin
combinations in a safe background environment, alerts store owners *before*
conflicts cause downtime, and provides one-click rollback to a previously
known-good state.

## Features

| Feature | Details |
|---|---|
| **Background Testing** | WP-Cron tests run every 6 hours (configurable) and also immediately after any plugin is activated or updated |
| **Conflict Detection** | Probes the site home URL and the admin health endpoint for HTTP 5xx errors; scans the PHP error log for fatal errors introduced by recent plugin changes |
| **Snapshot Manager** | Automatically captures the active-plugin list before every change; supports manual snapshots from the dashboard |
| **One-Click Rollback** | Restore any snapshot with a single button; the current state is auto-snapshotted first so the rollback itself is reversible |
| **Admin Dashboard** | Visual conflict list with severity badges, snapshot table, rollback history, and a "Run Test Now" button |
| **Email Alerts** | Optional email notifications to the site admin whenever conflicts are detected |
| **Auto Rollback** | Optional setting to automatically roll back when a critical conflict is detected |
| **Plugins List Integration** | Highlights plugins with active conflicts directly on the Plugins screen |

## Requirements

* WordPress 5.8+
* PHP 7.4+
* WooCommerce (optional – the plugin works with any combination of plugins)

## Installation

1. Upload the `intelligent-plugin-conflict-detector` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Navigate to **Tools → Conflict Detector** to view the dashboard.

## Usage

### Dashboard

Open **Tools → Conflict Detector** in the WordPress admin:

* **Conflicts tab** – lists all active (unresolved) conflicts with severity, plugin name, type, and message.  Each conflict can be individually resolved.  A *Clear All* button removes the entire log.
* **Snapshots tab** – lists all captured plugin-state snapshots.  Click **Rollback** next to any snapshot to restore that state.
* **Rollback History tab** – audit trail of every rollback that has been performed.
* **Settings link** – navigates to the settings page.

### Settings

Open **Tools → Conflict Detector → Settings** (or click the Settings tab):

| Option | Default | Description |
|---|---|---|
| Email Notifications | Off | Send an email when conflicts are detected |
| Alert Email Address | Admin email | Recipient for conflict alert emails |
| Auto Rollback | Off | Automatically restore the previous snapshot on a critical conflict |
| Scan Interval | 6 hours | How often the background scanner runs |

### Running Tests Manually

Click **Run Test Now** on the dashboard status bar.  The detector will immediately probe the site home URL and admin health endpoint for HTTP errors and scan the error log, then display any new conflicts.

### Creating Snapshots Manually

Click **Create Snapshot** on the dashboard status bar.  Enter an optional label and the current active-plugin list will be captured.

## Developer Hooks

| Hook | Type | Description |
|---|---|---|
| `ipcd_conflict_recorded` | Action | Fires after a conflict is recorded. Args: `$conflict` (array), `$conflict_id` (string) |
| `ipcd_conflicts_detected` | Action | Fires after a test run finds conflicts. Args: `$plugin_basename`, `$event`, `$conflict_ids[]` |
| `ipcd_before_test` | Action | Fires before a background test run. Args: `$plugin_basename`, `$event` |
| `ipcd_after_test` | Action | Fires after a background test run. Args: `$plugin_basename`, `$event`, `$conflict_ids[]` |
| `ipcd_rollback_complete` | Action | Fires after a successful rollback. Args: `$snapshot_id`, `$pre_rollback_id` |

## Development

```bash
# Install PHP dependencies (PHPUnit + Brain\Monkey)
cd intelligent-plugin-conflict-detector
composer install

# Run all unit tests
./vendor/bin/phpunit
```

The test suite uses [Brain\Monkey](https://brain-wp.github.io/BrainMonkey/) to mock WordPress functions so no live WordPress installation is required.

## File Structure

```
intelligent-plugin-conflict-detector/
├── intelligent-plugin-conflict-detector.php   Main plugin bootstrap
├── includes/
│   ├── class-plugin-state-manager.php         Snapshot capture & restore
│   ├── class-conflict-detector.php            Conflict recording & retrieval
│   ├── class-background-tester.php            WP-Cron based background testing
│   ├── class-rollback-manager.php             One-click rollback
│   └── class-notification-manager.php         Admin notices & email alerts
├── admin/
│   ├── class-admin.php                        Admin menus & AJAX handlers
│   ├── views/
│   │   ├── dashboard.php                      Dashboard view
│   │   └── settings.php                       Settings view
│   └── assets/
│       ├── css/admin.css
│       └── js/admin.js
├── tests/
│   ├── bootstrap.php
│   └── Unit/
│       ├── ConflictDetectorTest.php
│       ├── RollbackManagerTest.php
│       ├── BackgroundTesterTest.php
│       └── NotificationManagerTest.php
├── composer.json
└── phpunit.xml
```

## License

GPL-2.0-or-later – see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
