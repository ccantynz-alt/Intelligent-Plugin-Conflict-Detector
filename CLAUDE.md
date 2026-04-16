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
│   │   ├── WooCommerceAnalyzer.php    # WooCommerce-specific rules
│   │   ├── PreUpdateAnalyzer.php      # Pre-update simulation engine
│   │   ├── DependencyAnalyzer.php     # Bundled PHP library version conflicts
│   │   ├── JavaScriptAnalyzer.php     # JS global/prototype/jQuery conflicts
│   │   └── DatabaseAnalyzer.php       # Option/cron/CPT/table collisions
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
│   ├── Resolver/
│   │   ├── AutoResolver.php           # Auto-fix orchestrator
│   │   ├── HookPriorityResolver.php   # Hook priority adjustment patches
│   │   ├── ScriptResolver.php         # Duplicate script/style dequeue
│   │   └── CompatibilityPatch.php     # mu-plugin patch file generator
│   ├── CLI/
│   │   └── Commands.php               # WP-CLI integration
│   ├── Report/
│   │   └── ReportGenerator.php        # Professional HTML conflict reports
│   ├── Export/
│   │   └── ExportManager.php          # Export/import conflict profiles
│   ├── Multisite/
│   │   └── NetworkScanner.php         # Multisite network-wide scanning
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
| conflict_type | VARCHAR(50) | 'fatal_error', 'hook_conflict', 'resource_collision', 'function_redeclaration', 'class_collision', 'global_conflict', 'performance_degradation', 'dependency_conflict', 'js_global_conflict', 'js_jquery_override', 'js_prototype_pollution', 'js_localize_collision', 'db_table_collision', 'db_option_collision', 'db_cron_collision', 'db_cpt_collision', 'db_taxonomy_collision', 'db_meta_collision' |
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

### 7. Auto-Fix Engine (LOCKED)

The single biggest differentiator — no other WordPress tool can automatically resolve conflicts.

**Architecture:**
- All patches are generated as mu-plugin files in `wp-content/mu-plugins/jetstrike-patches/`
- A loader mu-plugin (`jetstrike-patch-loader.php`) includes all patches automatically
- Each patch is individually reversible with one click
- Patches load before regular plugins (mu-plugin loading order)

**Resolution Strategies:**

| Conflict Type | Fix Method | How It Works |
|---------------|-----------|--------------|
| Hook conflict | Priority adjustment | Moves one plugin's callback to a non-conflicting priority via reflection |
| Resource collision | Script/style dequeue | Dequeues the duplicate handle, keeping the first-loaded version |
| Function redeclaration | Function guard | Custom error handler suppresses "Cannot redeclare" fatal errors |
| Global conflict | Variable isolation | Snapshot/restore pattern so each plugin sees its own copy of the global |

**AJAX Endpoints:**
- `jetstrike_cd_auto_fix` — Apply auto-fix for a conflict
- `jetstrike_cd_revert_fix` — Revert a previously applied fix

### 8. Dependency Version Analyzer (LOCKED)

Detects when multiple plugins bundle incompatible versions of the same PHP library.

**Known Libraries Tracked:**
- guzzlehttp/guzzle, monolog/monolog, nesbot/carbon
- symfony/http-foundation, symfony/console
- league/container, league/csv
- stripe/stripe-php, firebase/php-jwt
- phpmailer/phpmailer, psr/log, psr/container, pelago/emogrifier

**Detection Methods:**
- Directory pattern matching (vendor/lib-name, lib/lib-name, includes/lib-name)
- Version extraction from class constants and composer.json
- Namespace prefix detection (php-scoper/strauss = safe, unprefixed = conflict)
- composer.lock parsing for locked dependency versions
- Major version divergence flagged as critical, minor as medium

### 9. JavaScript Conflict Analyzer (LOCKED)

No other WordPress tool analyzes JavaScript for conflicts.

**Detects:**
- Global namespace pollution (`window.X = ...`, top-level `var X = ...`)
- jQuery version overrides (deregistering WordPress's bundled jQuery)
- Prototype pollution (`Array.prototype.x = ...` etc.)
- `wp_localize_script` variable name collisions
- `wp_add_inline_script` global variable conflicts

**Approach:**
- Recursively scans plugin JS files (skips node_modules, vendor minified)
- Strips comments before analysis to reduce false positives
- Cross-references globals across plugins for collisions
- Maintains SAFE_GLOBALS whitelist (jQuery, wp, lodash, React, etc.)

### 10. Database Conflict Analyzer (LOCKED)

Detects database-level conflicts that cause silent data corruption.

**Detects:**
- Custom table name collisions (CREATE TABLE with same name)
- wp_options key collisions (same option name from different plugins)
- WP-Cron hook collisions (same hook name for different scheduled tasks)
- Custom post type slug conflicts (same CPT slug from different plugins)
- Taxonomy slug conflicts
- Transient key collisions
- Post meta key conflicts

**Approach:**
- Regex-based extraction from PHP source files
- Cross-references all extracted names across plugin boundaries
- Filters out WordPress core options and plugin-prefixed names (reduce noise)
- Recursive scanning with depth limits and file count safety caps

### 11. WP-CLI Integration (LOCKED)

Full command-line interface for professional developers and CI/CD pipelines.

**Commands:**
```
wp jetstrike scan [--type=<quick|full|targeted>] [--target=<plugin>] [--format=<table|json|csv|count>]
wp jetstrike conflicts [--severity=<level>] [--status=<status>] [--format=<format>]
wp jetstrike fix <conflict_id> [--dry-run]
wp jetstrike revert <conflict_id>
wp jetstrike patches [--format=<format>]
wp jetstrike status
wp jetstrike health
wp jetstrike reset [--yes]
```

**CI/CD Integration:**
- `wp jetstrike scan --format=json` outputs machine-readable JSON
- `wp jetstrike scan --format=count` returns just the conflict count (exit code friendly)
- `wp jetstrike conflicts --severity=critical --format=count` for threshold-based CI gates
- All scan results stored in database with `triggered_by: cli`

### 12. REST API Endpoints (LOCKED)


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

### 13. Subscription Tiers (LOCKED)

| Feature | Free | Pro ($79/yr) | Agency ($199/yr) |
|---------|------|-------------|-----------------|
| Quick Scan (static analysis) | Yes | Yes | Yes |
| Manual Full Scan | 1/week | Unlimited | Unlimited |
| Automated Background Scans | No | Yes | Yes |
| Pre-Update Simulation | No | Yes | Yes |
| WooCommerce Deep Analysis | No | Yes | Yes |
| **Auto-Fix Engine** | No | **Yes** | **Yes** |
| **Dependency Version Analysis** | No | **Yes** | **Yes** |
| **JavaScript Deep Analysis** | No | **Yes** | **Yes** |
| **Database Conflict Analysis** | No | **Yes** | **Yes** |
| **WP-CLI Integration** | No | **Yes** | **Yes** |
| Email Alerts | No | Yes | Yes |
| Slack Integration | No | No | Yes |
| REST API Access | No | No | Yes |
| Multi-site Support | No | No | Yes |
| Scan History | Last 3 | Last 50 | Unlimited |
| Performance Scoring | Basic | Advanced | Advanced |
| Priority Support | No | Yes | Yes |

### 14. Coding Standards (LOCKED)

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

### 15. Performance Constraints (LOCKED)

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
13. Security hardening + Freemius SDK integration
14. Cloud Intelligence layer (Telemetry + ConflictIntelligence)
15. Auto-Fix Engine (AutoResolver + HookPriorityResolver + ScriptResolver + CompatibilityPatch)
16. Dependency Analyzer + JavaScript Analyzer + Database Analyzer
17. WP-CLI Commands
18. Landing page (site/index.html)
19. Pre-Update Simulation Engine
20. Compatibility Matrix + Report Generator + Export/Import
21. Multisite Network Scanner

## Git Strategy

- Commit after each major subsystem is complete
- Descriptive commit messages explaining what was built
- All code on branch: `claude/woocommerce-conflict-detector-tb9Gg`
