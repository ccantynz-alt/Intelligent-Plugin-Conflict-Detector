<?php
/**
 * Data access layer for scans and conflicts.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Database;

final class Repository {

    /** @var \wpdb */
    private $wpdb;

    /** @var string */
    private string $scans_table;

    /** @var string */
    private string $conflicts_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->scans_table = $wpdb->prefix . 'jetstrike_scans';
        $this->conflicts_table = $wpdb->prefix . 'jetstrike_conflicts';
    }

    // ── Scans ──────────────────────────────────────────────────

    /**
     * Create a new scan record.
     *
     * @param array $data Scan data.
     * @return int Scan ID.
     */
    public function create_scan(array $data): int {
        $defaults = [
            'scan_type'    => 'full',
            'status'       => 'queued',
            'triggered_by' => 'manual',
            'created_at'   => current_time('mysql', true),
        ];

        $insert = array_merge($defaults, $data);

        $this->wpdb->insert($this->scans_table, $insert);

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Get a scan by ID.
     *
     * @param int $scan_id Scan ID.
     * @return object|null Scan record.
     */
    public function get_scan(int $scan_id): ?object {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->scans_table} WHERE id = %d",
                $scan_id
            )
        );

        return $result ?: null;
    }

    /**
     * Update a scan record.
     *
     * @param int   $scan_id Scan ID.
     * @param array $data    Data to update.
     * @return bool Success.
     */
    public function update_scan(int $scan_id, array $data): bool {
        $result = $this->wpdb->update(
            $this->scans_table,
            $data,
            ['id' => $scan_id]
        );

        return $result !== false;
    }

    /**
     * List scans with pagination.
     *
     * @param int    $page     Page number.
     * @param int    $per_page Items per page.
     * @param string $status   Filter by status.
     * @return array{items: array, total: int}
     */
    public function list_scans(int $page = 1, int $per_page = 20, string $status = ''): array {
        $where = '';
        $params = [];

        if ($status !== '') {
            $where = 'WHERE status = %s';
            $params[] = $status;
        }

        $total = (int) $this->wpdb->get_var(
            $status !== ''
                ? $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->scans_table} {$where}", ...$params)
                : "SELECT COUNT(*) FROM {$this->scans_table}"
        );

        $offset = ($page - 1) * $per_page;

        $query = "SELECT * FROM {$this->scans_table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$per_page, $offset]);

        $items = $this->wpdb->get_results(
            $this->wpdb->prepare($query, ...$query_params)
        );

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }

    /**
     * Delete a scan and its conflicts.
     *
     * @param int $scan_id Scan ID.
     * @return bool Success.
     */
    public function delete_scan(int $scan_id): bool {
        $this->wpdb->delete($this->conflicts_table, ['scan_id' => $scan_id]);
        $result = $this->wpdb->delete($this->scans_table, ['id' => $scan_id]);

        return $result !== false;
    }

    /**
     * Get the most recent completed scan.
     *
     * @return object|null Scan record.
     */
    public function get_latest_scan(): ?object {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->scans_table} WHERE status = %s ORDER BY completed_at DESC LIMIT 1",
                'completed'
            )
        );

        return $result ?: null;
    }

    /**
     * Check if a scan is currently running.
     *
     * @return bool True if a scan is in progress.
     */
    public function has_running_scan(): bool {
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->scans_table} WHERE status IN (%s, %s)",
                'queued',
                'running'
            )
        );

        return $count > 0;
    }

    // ── Conflicts ──────────────────────────────────────────────

    /**
     * Create a conflict record.
     *
     * @param array $data Conflict data.
     * @return int Conflict ID.
     */
    public function create_conflict(array $data): int {
        $defaults = [
            'severity'    => 'medium',
            'status'      => 'active',
            'detected_at' => current_time('mysql', true),
        ];

        $insert = array_merge($defaults, $data);

        $this->wpdb->insert($this->conflicts_table, $insert);

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Get a conflict by ID.
     *
     * @param int $conflict_id Conflict ID.
     * @return object|null Conflict record.
     */
    public function get_conflict(int $conflict_id): ?object {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->conflicts_table} WHERE id = %d",
                $conflict_id
            )
        );

        return $result ?: null;
    }

    /**
     * Update a conflict record.
     *
     * @param int   $conflict_id Conflict ID.
     * @param array $data        Data to update.
     * @return bool Success.
     */
    public function update_conflict(int $conflict_id, array $data): bool {
        $result = $this->wpdb->update(
            $this->conflicts_table,
            $data,
            ['id' => $conflict_id]
        );

        return $result !== false;
    }

    /**
     * List conflicts for a scan.
     *
     * @param int $scan_id Scan ID.
     * @return array Conflict records.
     */
    public function get_conflicts_for_scan(int $scan_id): array {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->conflicts_table} WHERE scan_id = %d ORDER BY severity ASC, detected_at DESC",
                $scan_id
            )
        );

        return $results ?: [];
    }

    /**
     * List all active conflicts.
     *
     * @param int $page     Page number.
     * @param int $per_page Items per page.
     * @return array{items: array, total: int}
     */
    public function list_active_conflicts(int $page = 1, int $per_page = 50): array {
        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->conflicts_table} WHERE status = %s",
                'active'
            )
        );

        $offset = ($page - 1) * $per_page;

        $items = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->conflicts_table} WHERE status = 'active' ORDER BY
                    CASE severity
                        WHEN 'critical' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'medium' THEN 3
                        WHEN 'low' THEN 4
                    END ASC,
                    detected_at DESC
                LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }

    /**
     * Get conflict counts grouped by severity.
     *
     * @return array<string, int> Severity => count.
     */
    public function get_conflict_summary(): array {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT severity, COUNT(*) as count FROM {$this->conflicts_table} WHERE status = %s GROUP BY severity",
                'active'
            )
        );

        $summary = [
            'critical' => 0,
            'high'     => 0,
            'medium'   => 0,
            'low'      => 0,
        ];

        if ($results) {
            foreach ($results as $row) {
                $summary[$row->severity] = (int) $row->count;
            }
        }

        return $summary;
    }

    /**
     * Check if a specific conflict between two plugins already exists.
     *
     * @param string $plugin_a First plugin.
     * @param string $plugin_b Second plugin.
     * @param string $type     Conflict type.
     * @return bool True if conflict exists.
     */
    public function conflict_exists(string $plugin_a, string $plugin_b, string $type): bool {
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->conflicts_table}
                WHERE status = 'active'
                AND conflict_type = %s
                AND (
                    (plugin_a = %s AND plugin_b = %s)
                    OR (plugin_a = %s AND plugin_b = %s)
                )",
                $type,
                $plugin_a,
                $plugin_b,
                $plugin_b,
                $plugin_a
            )
        );

        return $count > 0;
    }

    /**
     * Purge old scans beyond retention limit.
     *
     * @param int $keep Number of scans to retain.
     */
    public function purge_old_scans(int $keep = 50): void {
        $cutoff_id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->scans_table} ORDER BY created_at DESC LIMIT 1 OFFSET %d",
                $keep
            )
        );

        if ($cutoff_id === null) {
            return;
        }

        $cutoff_id = (int) $cutoff_id;

        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->conflicts_table} WHERE scan_id IN (SELECT id FROM {$this->scans_table} WHERE id <= %d)",
                $cutoff_id
            )
        );

        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->scans_table} WHERE id <= %d",
                $cutoff_id
            )
        );
    }
}
