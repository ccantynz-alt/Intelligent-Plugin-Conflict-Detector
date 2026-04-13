# CLAUDE.md — Intelligent Plugin Conflict Detector

## Project Identity
- **Product Name**: Jetstrike Conflict Detector
- **Slug**: `jetstrike-conflict-detector`
- **Text Domain**: `jetstrike-cd`
- **Namespace**: `Jetstrike\ConflictDetector`
- **Minimum PHP**: 7.4
- **Minimum WordPress**: 6.0
- **Tested Up To WordPress**: 6.7
- **WooCommerce Tested Up To**: 9.5
- **License**: GPL-2.0-or-later

## Architecture Decisions (LOCKED)

### 1. Plugin Bootstrap Pattern
- Single entry point: `jetstrike-conflict-detector.php`
- PSR-4 autoloading via Composer under `Jetstrike\ConflictDetector` namespace
- Singleton orchestrator `Plugin` class — initializes all subsystems
- Activation/deactivation hooks handled by dedicated classes
- Clean `uninstall.php` removes all data

### 2. Directory Structure
```
jetstrike-conflict-detector/
├── CLAUDE.md
├── jetstrike-conflict-detector.php    # Entry point
├── uninstall.php                      # Clean uninstall
├── composer.json
├── readme.txt                         # WordPress.org readme
├── assets/
│   ├── css/
│   │   └── admin-dashboard.css
│   ├── js/
│   │   └── admin-dashboard.js
│   └── images/
├── includes/
│   ├── Plugin.php                     # Main orchestrator
│   ├── Activator.php                  # Activation logic
│   ├── Deactivator.php                # Deactivation logic
│   ├── Scanner/
│   │   ├── ScanEngine.php             # Core scanning orchestrator
│   │   ├── SandboxEnvironment.php     # Isolated test environment
│   │   ├── BinarySearch.php           # Binary search isolation
│   │   └── ScanQueue.php              # Background job queue
│   ├── Analyzer/
│   │   ├── StaticAnalyzer.php         # PHP code static analysis
│   │   ├── HookAnalyzer.php           # WordPress hook/filter analysis
│   │   ├── ResourceAnalyzer.php       # JS/CSS/global collision detection
│   │   ├── PerformanceAnalyzer.php    # Performance impact scoring
│   │   └── WooCommerceAnalyzer.php    # WooCommerce-specific rules
│   ├── Monitor/
│   │   ├── BackgroundMonitor.php      # WP-Cron scheduled scanning
│   │   ├── UpdateWatcher.php          # Plugin update detection
│   │   └── HealthMonitor.php          # Continuous health monitoring
│   ├── API/
│   │   └── RestController.php         # REST API endpoints
│   ├── Notification/
│   │   ├── NotificationManager.php    # Notification orchestrator
│   │   ├── EmailNotifier.php          # Email alerts
│   │   └── SlackNotifier.php          # Slack webhook integration
│   ├── Database/
│   │   ├── Migrator.php               # Schema versioning & migrations
│   │   └── Repository.php             # Data access layer
│   └── Subscription/
│       ├── LicenseManager.php         # License key validation
│       └── FeatureFlags.php           # Free/Pro/Agency tier gating
└── templates/
    └── admin/
        ├── dashboard.php
        ├── scan-results.php
        └── settings.php
```

### 3. Database Schema
Two custom tables prefixed with `{wpdb->prefix}jetstrike_`:

**`jetstrike_scans`** — Scan job records
| Column | Type | Purpose |
|--------|------|---------|
| id | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| scan_type | VARCHAR(50) | 'full', 'quick', 'targeted', 'pre_update' |
| status | VARCHAR(20) | 'queued', 'running', 'completed', 'failed', 'cancelled' |
| plugins_tested | TEXT | JSON array of plugin slugs tested |
| conflicts_found | INT UNSIGNED | Count of conflicts detected |
| started_at | DATETIME | Scan start time |
| completed_at | DATETIME | Scan end time |
| results | LONGTEXT | Full JSON results payload |
| triggered_by | VARCHAR(50) | 'manual', 'scheduled', 'update_watcher', 'api' |
| created_at | DATETIME | Record creation |

**`jetstrike_conflicts`** — Detected conflicts
| Column | Type | Purpose |
|--------|------|---------|
| id | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| scan_id | BIGINT UNSIGNED | FK to scans table |
| plugin_a | VARCHAR(255) | First plugin file path |
| plugin_b | VARCHAR(255) | Second plugin file path |
| conflict_type | VARCHAR(50) | 'fatal_error', 'hook_conflict', 'resource_collision', 'function_redeclaration', 'class_collision', 'global_conflict', 'performance_degradation' |
| severity | VARCHAR(20) | 'critical', 'high', 'medium', 'low' |
| description | TEXT | Human-readable conflict description |
| technical_details | LONGTEXT | JSON with stack traces, hook names, etc. |
| recommendation | TEXT | Actionable fix suggestion |
| status | VARCHAR(20) | 'active', 'resolved', 'ignored', 'false_positive' |
| detected_at | DATETIME | When conflict was found |
| resolved_at | DATETIME | When marked resolved |

### 4. Scanner Architecture (LOCKED)

**Scan Types:**
- **Quick Scan**: Static analysis only — no runtime testing. Analyzes PHP source for function/class collisions, hook conflicts, resource issues. Runs in < 30 seconds.
- **Full Scan**: Static analysis + runtime sandbox testing of all plugin pair combinations. Uses binary search to isolate conflicts efficiently.
- **Targeted Scan**: Tests a specific plugin against all others.
- **Pre-Update Scan**: Simulates an update by analyzing the new version's code before applying.

**Sandbox Approach:**
- Spawns isolated loopback HTTP requests to the site with a secure single-use token
- Each request activates a specific plugin combination and checks for:
  - HTTP 500 / fatal errors
  - PHP warnings/notices in output
  - WP error responses
  - Significant response time degradation (> 3x baseline)
- Uses WordPress's `wp_remote_get()` with custom headers for auth
- The sandbox endpoint is a REST route that activates/deactivates plugins in a must-use plugin filter

**Binary Search Algorithm:**
- For N plugins, worst case is O(N log N) tests instead of O(N²)
- Splits active plugins into halves, tests each half
- Recursively narrows down to the specific conflicting pair
- Falls back to pairwise testing for the final 4 plugins

### 5. Static Analysis Rules (LOCKED)

**Function Redeclaration Detection:**
- Tokenize PHP files with `token_get_all()`
- Extract all function declarations per plugin
- Cross-reference for duplicates across plugins

**Class Name Collision Detection:**
- Extract class/interface/trait declarations
- Check for same-name classes across plugins (excluding namespaced)

**Hook Conflict Detection:**
- Parse `add_action()` / `add_filter()` calls
- Identify same hook + same priority from different plugins
- Flag hooks that `remove_all_actions()` / `remove_all_filters()`

**Global Variable Conflicts:**
- Detect `global $var` declarations
- Cross-reference same global variable names across plugins

**Resource Collision Detection:**
- Parse `wp_enqueue_script()` / `wp_enqueue_style()` calls
- Detect duplicate handle names across plugins

### 6. WooCommerce-Specific Rules (LOCKED)

- Payment gateway conflicts (multiple gateways hooking `woocommerce_payment_gateways`)
- Checkout field manipulation conflicts
- Cart calculation hook interference
- Template override collisions (same template overridden by multiple plugins)
- REST API namespace conflicts under `wc/` prefix
- HPOS (High-Performance Order Storage) compatibility checks
- Checkout Block vs Classic Checkout conflicts

### 7. REST API Endpoints (LOCKED)

All under namespace `jetstrike/v1`:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/scans` | Start a new scan |
| GET | `/scans` | List all scans |
| GET | `/scans/{id}` | Get scan details |
| DELETE | `/scans/{id}` | Cancel/delete scan |
| GET | `/conflicts` | List all conflicts |
| PATCH | `/conflicts/{id}` | Update conflict status |
| GET | `/plugins` | List plugins with health scores |
| GET | `/status` | Overall system health |
| POST | `/settings` | Update scanner settings |
| GET | `/settings` | Get scanner settings |

### 8. Subscription Tiers (LOCKED)

| Feature | Free | Pro ($79/yr) | Agency ($199/yr) |
|---------|------|-------------|-----------------|
| Quick Scan (static analysis) | Yes | Yes | Yes |
| Manual Full Scan | 1/week | Unlimited | Unlimited |
| Automated Background Scans | No | Yes | Yes |
| Pre-Update Simulation | No | Yes | Yes |
| WooCommerce Deep Analysis | No | Yes | Yes |
| Email Alerts | No | Yes | Yes |
| Slack Integration | No | No | Yes |
| REST API Access | No | No | Yes |
| Multi-site Support | No | No | Yes |
| Scan History | Last 3 | Last 50 | Unlimited |
| Performance Scoring | Basic | Advanced | Advanced |
| Priority Support | No | Yes | Yes |

### 9. Coding Standards (LOCKED)

- Follow WordPress Coding Standards for PHP
- Use strict typing: `declare(strict_types=1)` in all files
- All classes are `final` unless explicitly designed for inheritance
- All public methods have PHPDoc blocks
- Database queries use `$wpdb->prepare()` — no exceptions
- All user input sanitized with `sanitize_*()` functions
- All output escaped with `esc_*()` functions
- Nonce verification on all form submissions and AJAX
- Capability checks (`current_user_can('manage_options')`) on all admin actions
- REST API uses `permission_callback` on every route

### 10. Performance Constraints (LOCKED)

- Quick scan must complete in < 30 seconds for 30 plugins
- Full scan processes max 5 plugin pairs per cron tick (to avoid timeouts)
- Background monitor runs every 6 hours by default
- All database queries are indexed
- Scan results are cached in transients (1 hour TTL)
- Admin dashboard AJAX polling interval: 5 seconds during active scan, 60 seconds otherwise

## Build Order (LOCKED)

1. Main plugin file + Composer autoloading
2. Database schema + Migrator + Repository
3. Plugin orchestrator + Activator + Deactivator
4. Static Analyzer (hook, function, class, global, resource analysis)
5. WooCommerce Analyzer
6. Scan Engine + Binary Search + Sandbox Environment
7. Scan Queue + Background Monitor + Update Watcher
8. REST API Controller
9. Notification Manager + Email + Slack
10. License Manager + Feature Flags
11. Admin Dashboard (PHP templates + CSS + JS)
12. Health Monitor

## Git Strategy

- Commit after each major subsystem is complete
- Descriptive commit messages explaining what was built
- All code on branch: `claude/woocommerce-conflict-detector-tb9Gg`
