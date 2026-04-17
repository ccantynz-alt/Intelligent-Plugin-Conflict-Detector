<?php

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Tests\Unit;

use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Monitor\HealthMonitor;
use PHPUnit\Framework\TestCase;

final class HealthMonitorTest extends TestCase {

    private HealthMonitor $monitor;

    protected function setUp(): void {
        jetstrike_test_reset_wp_state();

        $repo = new Repository();
        $this->monitor = new HealthMonitor($repo);
    }

    private function mockConflictSummary(int $critical, int $high, int $medium, int $low): void {
        // get_conflict_summary queries with GROUP BY severity
        // and returns rows as objects; mock get_results to return those rows
        $rows = [];
        foreach (['critical' => $critical, 'high' => $high, 'medium' => $medium, 'low' => $low] as $sev => $count) {
            if ($count > 0) {
                $rows[] = (object) ['severity' => $sev, 'count' => (string) $count];
            }
        }
        $GLOBALS['wpdb']->__mock_results['get_results'] = $rows;
    }

    private function mockLatestScan(?string $completed_at): void {
        if ($completed_at === null) {
            $GLOBALS['wpdb']->__mock_results['get_row'] = null;
        } else {
            $GLOBALS['wpdb']->__mock_results['get_row'] = (object) [
                'id' => 1,
                'scan_type' => 'full',
                'status' => 'completed',
                'completed_at' => $completed_at,
                'created_at' => $completed_at,
                'conflicts_found' => 0,
                'triggered_by' => 'manual',
            ];
        }
    }

    public function test_perfect_health_score_with_no_conflicts(): void {
        $this->mockConflictSummary(0, 0, 0, 0);
        $this->mockLatestScan(gmdate('Y-m-d H:i:s'));

        $result = $this->monitor->calculate_health_score();

        $this->assertSame(100, $result['score']);
        $this->assertSame('A', $result['grade']);
        $this->assertIsArray($result['factors']);
        $this->assertArrayHasKey('checked_at', $result);
    }

    public function test_critical_conflicts_reduce_score(): void {
        $this->mockConflictSummary(2, 0, 0, 0);
        $this->mockLatestScan(gmdate('Y-m-d H:i:s'));

        $result = $this->monitor->calculate_health_score();

        $this->assertLessThan(100, $result['score']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
    }

    public function test_grade_f_for_many_critical_conflicts(): void {
        $this->mockConflictSummary(10, 5, 3, 2);
        $this->mockLatestScan(null);

        $result = $this->monitor->calculate_health_score();

        $this->assertLessThanOrEqual(40, $result['score']);
        $this->assertContains($result['grade'], ['D', 'F']);
    }

    public function test_get_health_data_includes_components(): void {
        $this->mockConflictSummary(0, 0, 0, 0);
        $this->mockLatestScan(gmdate('Y-m-d H:i:s'));

        $data = $this->monitor->get_health_data();

        $this->assertArrayHasKey('components', $data);
        $this->assertArrayHasKey('score', $data);
        $this->assertArrayHasKey('grade', $data);
        $this->assertArrayHasKey('factors', $data);
    }

    public function test_health_score_returns_valid_grades(): void {
        $valid_grades = ['A', 'B', 'C', 'D', 'F'];

        $this->mockConflictSummary(1, 1, 1, 1);
        $this->mockLatestScan(null);

        $result = $this->monitor->calculate_health_score();

        $this->assertContains($result['grade'], $valid_grades);
    }

    public function test_score_is_bounded_0_to_100(): void {
        $this->mockConflictSummary(50, 50, 50, 50);
        $this->mockLatestScan(null);

        $result = $this->monitor->calculate_health_score();

        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }
}
