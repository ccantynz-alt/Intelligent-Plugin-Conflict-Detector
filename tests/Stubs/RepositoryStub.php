<?php

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Tests\Stubs;

/**
 * Repository test stub that mimics the Repository interface without requiring $wpdb.
 *
 * Since Repository is final and cannot be mocked by PHPUnit, this stub
 * provides configurable return values for all public methods.
 */
class RepositoryStub {

    public array $conflict_summary = [
        'critical' => 0,
        'high'     => 0,
        'medium'   => 0,
        'low'      => 0,
    ];

    public ?object $latest_scan = null;

    public array $active_conflicts = ['items' => [], 'total' => 0];

    public bool $has_running = false;

    public function get_conflict_summary(): array {
        return $this->conflict_summary;
    }

    public function get_latest_scan(): ?object {
        return $this->latest_scan;
    }

    public function list_active_conflicts(int $page = 1, int $per_page = 50): array {
        return $this->active_conflicts;
    }

    public function has_running_scan(): bool {
        return $this->has_running;
    }

    public function get_scan(int $scan_id): ?object {
        return null;
    }

    public function get_conflict(int $conflict_id): ?object {
        return null;
    }

    public function update_conflict(int $conflict_id, array $data): bool {
        return true;
    }

    public function create_scan(array $data): int {
        return 1;
    }

    public function update_scan(int $scan_id, array $data): bool {
        return true;
    }

    public function create_conflict(array $data): int {
        return 1;
    }

    public function list_scans(int $page = 1, int $per_page = 20, string $status = ''): array {
        return ['items' => [], 'total' => 0];
    }

    public function get_conflicts_for_scan(int $scan_id): array {
        return [];
    }

    public function conflict_exists(string $plugin_a, string $plugin_b, string $type): bool {
        return false;
    }

    public function purge_old_scans(int $keep = 50): void {}

    public function delete_scan(int $scan_id): bool {
        return true;
    }
}
