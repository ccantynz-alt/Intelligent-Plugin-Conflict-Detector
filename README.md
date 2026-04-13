# Intelligent Plugin Conflict Detector

A WordPress plugin that automatically detects plugin conflicts by testing combinations in a safe, sandboxed environment. Designed for WooCommerce store owners who need to identify which plugins cause issues — without the painful process of manually deactivating plugins one by one.

## Features

- **Static Analysis**: Detects function/class name collisions, hook conflicts, resource handle duplicates, and shared library version conflicts across active plugins
- **Sandbox Testing**: Safely tests plugin combinations via loopback HTTP requests with selective plugin activation — no impact on your live site
- **Pairwise Testing**: Systematically tests every pair of active plugins to pinpoint exactly which combination causes issues
- **Isolation Testing**: Tests each plugin individually to identify single-plugin problems
- **Background Monitoring**: WP-Cron powered scheduled scans with configurable frequency (hourly, daily, weekly)
- **Smart Triggers**: Automatically runs conflict checks when plugins are activated, deactivated, or updated
- **Known Conflict Database**: Pre-built detection for common incompatible plugin pairs (dual SEO plugins, conflicting page builders, etc.)
- **Error Log Analysis**: Parses PHP error logs to correlate errors with specific plugins
- **Email Notifications**: Sends alerts when new conflicts are detected
- **Admin Dashboard**: Visual dashboard with statistics, conflict lists, scan history, and filtering
- **REST API**: Full REST API for programmatic access to conflict data and scan triggers
- **Clean Uninstall**: Removes all database tables, options, and transients on deletion

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later

## Installation

1. Download or clone this repository
2. Upload the plugin folder to `wp-content/plugins/`
3. Activate the plugin through the WordPress admin **Plugins** screen
4. Navigate to **Plugin Conflicts** in the admin menu to access the dashboard

## Usage

### Dashboard

The main dashboard provides:
- At-a-glance statistics (total conflicts, active, critical, resolved)
- Quick access to scan actions (Full Scan, Quick Health Check, Isolation Test)
- Monitoring status and last scan time
- List of recent unresolved conflicts

### Running Scans

| Scan Type | Description |
|-----------|-------------|
| **Full Scan** | Runs static analysis and pairwise sandbox tests on all active plugins |
| **Quick Health Check** | Checks error logs, known conflict pairs, and site health — fast and lightweight |
| **Isolation Test** | Tests each plugin individually via sandbox to find single-plugin issues |

### Conflict Severity Levels

| Level | Description |
|-------|-------------|
| **Critical** | Fatal errors, site-breaking issues |
| **High** | Warnings, function/class collisions |
| **Medium** | Hook conflicts, dependency overlaps |
| **Low** | Notices, style handle duplicates |

### Settings

- **Background Monitoring**: Enable/disable automatic scheduled scans
- **Scan Frequency**: Hourly, Twice Daily, Daily, or Weekly
- **Error Threshold**: Minimum error level to report (Notice, Warning, Error)
- **Email Notifications**: Toggle and configure notification email address

## REST API

All endpoints require `manage_options` capability and are available under `/wp-json/ipcd/v1/`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/conflicts` | List conflicts with filtering (severity, status, plugin) |
| GET | `/conflicts/{id}` | Get a single conflict |
| POST | `/conflicts/{id}/resolve` | Mark a conflict as resolved |
| GET | `/stats` | Dashboard statistics |
| POST | `/scan` | Trigger a manual scan (type: full, quick, isolation) |
| GET | `/scans` | Scan history |
| GET | `/plugins` | List active plugins |
| GET | `/settings` | Get current settings |
| POST | `/settings` | Update settings |

## How It Works

### Static Analysis
The detector scans PHP source files of each active plugin, extracting:
- Function definitions (detects name collisions across plugins)
- Class definitions (detects class name conflicts)
- Hook registrations (`add_action`/`add_filter` at the same priority on sensitive hooks)
- Script/style handle registrations (detects `wp_enqueue_script`/`wp_enqueue_style` handle conflicts)
- Bundled library directories (detects version conflicts from duplicate Composer packages, etc.)

### Sandbox Testing
For runtime conflict detection, the plugin:
1. Generates a unique test token and stores the test configuration in a WordPress transient
2. Creates a temporary must-use plugin that overrides `active_plugins` for requests matching the token
3. Makes a loopback HTTP request with only the target plugin pair active
4. Captures any PHP errors via a custom error handler during the sandboxed request
5. Cleans up the temporary MU-plugin and transient after each test

### Automatic Monitoring
- **Plugin Activation**: When a new plugin is activated, an automatic pairwise conflict check is scheduled
- **Plugin Deactivation**: Related conflicts are auto-resolved when a plugin is deactivated
- **Plugin Updates**: A full scan is scheduled after plugin updates complete
- **Scheduled Scans**: Configurable WP-Cron based recurring scans

## File Structure

```
intelligent-plugin-conflict-detector/
├── intelligent-plugin-conflict-detector.php   # Main plugin file
├── uninstall.php                              # Clean uninstall handler
├── includes/
│   ├── class-ipcd-database.php               # Database operations
│   ├── class-ipcd-conflict-detector.php      # Core detection engine
│   ├── class-ipcd-sandbox-tester.php         # Safe sandbox testing
│   ├── class-ipcd-background-monitor.php     # WP-Cron scheduling
│   ├── class-ipcd-rest-api.php               # REST API endpoints
│   └── class-ipcd-admin-page.php             # Admin UI registration
├── templates/
│   └── admin-page.php                        # Admin page template
└── assets/
    ├── css/
    │   └── admin.css                         # Admin styles
    └── js/
        └── admin.js                          # Admin JavaScript
```

## License

GPL-2.0-or-later
