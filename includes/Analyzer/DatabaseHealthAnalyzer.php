<?php
/**
 * Database Health Analyzer — finds bloat, orphaned data, and performance killers.
 *
 * WooCommerce stores accumulate massive database bloat over time:
 * - Autoloaded options that slow every single page load
 * - Post revisions consuming gigabytes
 * - Orphaned postmeta from deleted products/orders
 * - Transients that never expire
 * - Orphaned term relationships
 * - Uninstalled plugin data left behind
 *
 * This analyzer identifies these issues and quantifies their performance impact.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class DatabaseHealthAnalyzer {

    /**
     * Run a full database health analysis.
     *
     * @return array{
     *   score: int,
     *   issues: array,
     *   stats: array,
     *   recommendations: array
     * }
     */
    public function analyze(): array {
        global $wpdb;

        $issues = [];
        $score = 100;
        $stats = [];

        // 1. Autoload bloat analysis.
        $autoload = $this->analyze_autoload($wpdb);
        $stats['autoload'] = $autoload;
        if ($autoload['total_size_bytes'] > 1048576) { // > 1MB
            $size_mb = round($autoload['total_size_bytes'] / 1048576, 1);
            $issues[] = [
                'type'     => 'autoload_bloat',
                'severity' => $size_mb > 5 ? 'critical' : ($size_mb > 2 ? 'high' : 'medium'),
                'message'  => sprintf(
                    'Your autoloaded options total %sMB. WordPress loads ALL of this data on every single ' .
                    'page load — for every visitor, every admin page, every AJAX request. This adds %dms ' .
                    'to every request. The top offenders are: %s.',
                    $size_mb,
                    (int) ($size_mb * 200),
                    implode(', ', array_slice(
                        array_map(function ($row) {
                            return $row['name'] . ' (' . $this->format_bytes((int) $row['size']) . ')';
                        }, $autoload['top_offenders']),
                        0, 5
                    ))
                ),
                'data'    => $autoload,
                'savings' => sprintf('Fixing this could save %dms on every page load.', (int) ($size_mb * 150)),
            ];
            $score -= $size_mb > 5 ? 30 : ($size_mb > 2 ? 20 : 10);
        }

        // 2. Post revision bloat.
        $revisions = $this->count_revisions($wpdb);
        $stats['revisions'] = $revisions;
        if ($revisions['count'] > 1000) {
            $issues[] = [
                'type'     => 'revision_bloat',
                'severity' => $revisions['count'] > 10000 ? 'high' : 'medium',
                'message'  => sprintf(
                    'Your database contains %s post revisions consuming approximately %s. ' .
                    'WordPress stores every saved version of every post and page by default. ' .
                    'Most of these are unnecessary and slow down database queries.',
                    number_format($revisions['count']),
                    $this->format_bytes($revisions['estimated_bytes'])
                ),
                'data'    => $revisions,
                'savings' => 'Cleaning revisions could reclaim ' . $this->format_bytes($revisions['estimated_bytes']) . ' of database space.',
            ];
            $score -= $revisions['count'] > 10000 ? 15 : 8;
        }

        // 3. Transient bloat.
        $transients = $this->count_transients($wpdb);
        $stats['transients'] = $transients;
        if ($transients['expired'] > 500) {
            $issues[] = [
                'type'     => 'transient_bloat',
                'severity' => 'medium',
                'message'  => sprintf(
                    'Your database contains %s expired transients that should have been cleaned up. ' .
                    'These are temporary cache entries that were never deleted. ' .
                    'They add unnecessary rows to your options table and slow queries.',
                    number_format($transients['expired'])
                ),
                'data' => $transients,
            ];
            $score -= 8;
        }

        // 4. Orphaned postmeta.
        $orphaned_meta = $this->count_orphaned_postmeta($wpdb);
        $stats['orphaned_postmeta'] = $orphaned_meta;
        if ($orphaned_meta > 5000) {
            $issues[] = [
                'type'     => 'orphaned_postmeta',
                'severity' => $orphaned_meta > 50000 ? 'high' : 'medium',
                'message'  => sprintf(
                    'Found %s orphaned postmeta rows — metadata entries for posts/products that no longer exist. ' .
                    'This is common after bulk-deleting WooCommerce orders or products. ' .
                    'These rows slow down every database query that touches postmeta.',
                    number_format($orphaned_meta)
                ),
                'count' => $orphaned_meta,
            ];
            $score -= $orphaned_meta > 50000 ? 15 : 8;
        }

        // 5. Orphaned term relationships.
        $orphaned_terms = $this->count_orphaned_term_relationships($wpdb);
        $stats['orphaned_term_relationships'] = $orphaned_terms;
        if ($orphaned_terms > 1000) {
            $issues[] = [
                'type'     => 'orphaned_terms',
                'severity' => 'low',
                'message'  => sprintf(
                    'Found %s orphaned term relationships — category/tag assignments for content that ' .
                    'no longer exists.',
                    number_format($orphaned_terms)
                ),
                'count' => $orphaned_terms,
            ];
            $score -= 5;
        }

        // 6. Large options table.
        $options_count = $this->count_options($wpdb);
        $stats['options_count'] = $options_count;
        if ($options_count > 5000) {
            $issues[] = [
                'type'     => 'options_bloat',
                'severity' => $options_count > 20000 ? 'high' : 'medium',
                'message'  => sprintf(
                    'Your wp_options table has %s rows. A healthy WordPress site typically has 200-500. ' .
                    'Plugins that store per-post or per-user data in options (instead of proper tables) ' .
                    'cause this bloat. It slows down every options query.',
                    number_format($options_count)
                ),
                'count' => $options_count,
            ];
            $score -= $options_count > 20000 ? 15 : 8;
        }

        // 7. Table size overview.
        $table_sizes = $this->get_table_sizes($wpdb);
        $stats['table_sizes'] = $table_sizes;
        $total_db_mb = array_sum(array_column($table_sizes, 'size_mb'));
        $stats['total_db_mb'] = round($total_db_mb, 1);

        if ($total_db_mb > 500) {
            $issues[] = [
                'type'     => 'large_database',
                'severity' => $total_db_mb > 2000 ? 'high' : 'medium',
                'message'  => sprintf(
                    'Your total database size is %sMB. Large databases slow down backups, ' .
                    'migrations, and complex queries. The largest tables are: %s.',
                    number_format($total_db_mb, 0),
                    implode(', ', array_slice(
                        array_map(function ($t) {
                            return $t['table'] . ' (' . $t['size_mb'] . 'MB, ' .
                                number_format($t['rows']) . ' rows)';
                        }, $table_sizes),
                        0, 3
                    ))
                ),
                'total_mb' => $total_db_mb,
            ];
            $score -= $total_db_mb > 2000 ? 15 : 8;
        }

        // 8. Missing indexes on common query patterns.
        $missing_indexes = $this->check_missing_indexes($wpdb);
        $stats['missing_indexes'] = $missing_indexes;
        if (! empty($missing_indexes)) {
            $issues[] = [
                'type'     => 'missing_indexes',
                'severity' => 'medium',
                'message'  => sprintf(
                    'Found %d table(s) with potentially missing indexes: %s. ' .
                    'Missing indexes force the database to scan entire tables for queries, ' .
                    'which gets dramatically slower as data grows.',
                    count($missing_indexes),
                    implode(', ', array_column($missing_indexes, 'table'))
                ),
                'indexes' => $missing_indexes,
            ];
            $score -= 10;
        }

        // Build recommendations.
        $recommendations = $this->build_recommendations($issues, $stats);

        return [
            'score'           => max(0, $score),
            'issues'          => $issues,
            'stats'           => $stats,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Analyze autoloaded options.
     */
    private function analyze_autoload(object $wpdb): array {
        $results = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) AS size
             FROM {$wpdb->options}
             WHERE autoload = 'yes'
             ORDER BY LENGTH(option_value) DESC
             LIMIT 50"
        );

        $total_row = $wpdb->get_row(
            "SELECT COUNT(*) AS cnt, SUM(LENGTH(option_value)) AS total_size
             FROM {$wpdb->options}
             WHERE autoload = 'yes'"
        );

        $top_offenders = [];
        foreach (array_slice($results ?: [], 0, 20) as $row) {
            $top_offenders[] = [
                'name' => $row->option_name,
                'size' => (int) $row->size,
            ];
        }

        return [
            'count'            => (int) ($total_row->cnt ?? 0),
            'total_size_bytes' => (int) ($total_row->total_size ?? 0),
            'top_offenders'    => $top_offenders,
        ];
    }

    /**
     * Count post revisions.
     */
    private function count_revisions(object $wpdb): array {
        $result = $wpdb->get_row(
            "SELECT COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type = 'revision'"
        );

        $count = (int) ($result->cnt ?? 0);

        return [
            'count'           => $count,
            'estimated_bytes' => $count * 5000,
        ];
    }

    /**
     * Count transients (total and expired).
     */
    private function count_transients(object $wpdb): array {
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_%'
             AND option_name NOT LIKE '_transient_timeout_%'"
        );

        $expired = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} a
             JOIN {$wpdb->options} b ON b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
             WHERE a.option_name LIKE '_transient_%'
             AND a.option_name NOT LIKE '_transient_timeout_%'
             AND b.option_value < UNIX_TIMESTAMP()"
        );

        return [
            'total'   => $total,
            'expired' => $expired,
        ];
    }

    /**
     * Count orphaned postmeta rows.
     */
    private function count_orphaned_postmeta(object $wpdb): int {
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.ID IS NULL"
        );
    }

    /**
     * Count orphaned term relationships.
     */
    private function count_orphaned_term_relationships(object $wpdb): int {
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
             LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
             WHERE p.ID IS NULL"
        );
    }

    /**
     * Count total options.
     */
    private function count_options(object $wpdb): int {
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
    }

    /**
     * Get table sizes sorted by size descending.
     */
    private function get_table_sizes(object $wpdb): array {
        $tables = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT table_name AS 'table',
                        ROUND((data_length + index_length) / 1048576, 1) AS size_mb,
                        table_rows AS 'rows'
                 FROM information_schema.TABLES
                 WHERE table_schema = %s
                 ORDER BY (data_length + index_length) DESC
                 LIMIT 20",
                DB_NAME
            )
        );

        $result = [];
        foreach ($tables ?: [] as $t) {
            $result[] = [
                'table'   => $t->table,
                'size_mb' => (float) $t->size_mb,
                'rows'    => (int) $t->rows,
            ];
        }

        return $result;
    }

    /**
     * Check for missing indexes on common query patterns.
     */
    private function check_missing_indexes(object $wpdb): array {
        $missing = [];

        $postmeta_indexes = $wpdb->get_results(
            "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name != 'PRIMARY'"
        );

        $has_meta_value_index = false;
        foreach ($postmeta_indexes ?: [] as $idx) {
            if ($idx->Column_name === 'meta_value') {
                $has_meta_value_index = true;
            }
        }

        $postmeta_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}");
        if (! $has_meta_value_index && $postmeta_rows > 100000) {
            $missing[] = [
                'table'  => $wpdb->postmeta,
                'column' => 'meta_value',
                'reason' => 'Large postmeta table without meta_value index — queries filtering by meta_value will be slow.',
                'rows'   => $postmeta_rows,
            ];
        }

        return $missing;
    }

    /**
     * Build actionable recommendations based on issues found.
     */
    private function build_recommendations(array $issues, array $stats): array {
        $recs = [];

        foreach ($issues as $issue) {
            switch ($issue['type']) {
                case 'autoload_bloat':
                    $recs[] = [
                        'priority' => 1,
                        'action'   => 'Audit autoloaded options and set large, infrequently-accessed options to autoload=no. ' .
                            'Focus on the top offenders first. Consider using an object cache (Redis/Memcached) for persistent caching.',
                        'impact'   => 'Could reduce page load time by ' . (isset($issue['data']['total_size_bytes'])
                            ? (int) ($issue['data']['total_size_bytes'] / 1048576 * 150) . 'ms'
                            : '200ms+') . ' on every request.',
                    ];
                    break;

                case 'revision_bloat':
                    $recs[] = [
                        'priority' => 2,
                        'action'   => 'Delete old post revisions and limit future revisions by adding ' .
                            'define(\'WP_POST_REVISIONS\', 5) to wp-config.php.',
                        'impact'   => 'Reclaim ' . $this->format_bytes($stats['revisions']['estimated_bytes'] ?? 0) .
                            ' of database space and speed up post queries.',
                    ];
                    break;

                case 'orphaned_postmeta':
                    $recs[] = [
                        'priority' => 2,
                        'action'   => 'Clean up orphaned postmeta entries. These are leftovers from deleted posts, ' .
                            'products, or orders. Use a database cleanup plugin or run a targeted DELETE query.',
                        'impact'   => 'Speeds up all queries involving post metadata, which includes most WooCommerce operations.',
                    ];
                    break;

                case 'transient_bloat':
                    $recs[] = [
                        'priority' => 3,
                        'action'   => 'Delete expired transients. If you have an object cache (Redis/Memcached), ' .
                            'transients are stored there instead and this issue resolves itself.',
                        'impact'   => 'Reduces options table size and improves autoload performance.',
                    ];
                    break;

                case 'large_database':
                    $recs[] = [
                        'priority' => 3,
                        'action'   => 'Review the largest tables and consider archiving old data. For WooCommerce, ' .
                            'old completed orders older than 2 years can often be exported and archived.',
                        'impact'   => 'Faster backups, faster migrations, and improved query performance across the board.',
                    ];
                    break;
            }
        }

        usort($recs, function ($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        return $recs;
    }

    /**
     * Format bytes into human-readable string.
     */
    private function format_bytes(int $bytes): string {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . 'GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . 'MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . 'KB';
        }

        return $bytes . ' bytes';
    }
}
