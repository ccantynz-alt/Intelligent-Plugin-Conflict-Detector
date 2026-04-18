<?php
/**
 * Health monitor — continuous site health scoring and monitoring.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Monitor;

use Jetstrike\ConflictDetector\Database\Repository;

final class HealthMonitor {

    /** @var Repository */
    private Repository $repository;

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action('jetstrike_cd_health_check', [$this, 'run_health_check']);

        // Integrate with WordPress Site Health.
        add_filter('site_status_tests', [$this, 'register_site_health_tests']);
    }

    /**
     * Run periodic health check.
     */
    public function run_health_check(): void {
        $health = $this->calculate_health_score();
        update_option('jetstrike_cd_health_score', $health);
    }

    /**
     * Calculate overall site health score.
     *
     * @return array{score: int, grade: string, factors: array, checked_at: string}
     */
    public function calculate_health_score(): array {
        $score = 100;
        $factors = [];

        // Factor 1: Active conflict count.
        $conflict_summary = $this->repository->get_conflict_summary();
        $total_conflicts = array_sum($conflict_summary);

        if ($conflict_summary['critical'] > 0) {
            $deduction = $conflict_summary['critical'] * 20;
            $score -= $deduction;
            $factors[] = [
                'name'      => 'critical_conflicts',
                'impact'    => -$deduction,
                'message'   => sprintf('%d critical conflict(s) detected', $conflict_summary['critical']),
            ];
        }

        if ($conflict_summary['high'] > 0) {
            $deduction = $conflict_summary['high'] * 10;
            $score -= $deduction;
            $factors[] = [
                'name'      => 'high_conflicts',
                'impact'    => -$deduction,
                'message'   => sprintf('%d high-severity conflict(s) detected', $conflict_summary['high']),
            ];
        }

        if ($conflict_summary['medium'] > 0) {
            $deduction = $conflict_summary['medium'] * 5;
            $score -= $deduction;
            $factors[] = [
                'name'      => 'medium_conflicts',
                'impact'    => -$deduction,
                'message'   => sprintf('%d medium-severity conflict(s) detected', $conflict_summary['medium']),
            ];
        }

        // Factor 2: Time since last scan.
        $latest_scan = $this->repository->get_latest_scan();

        if ($latest_scan === null) {
            $score -= 15;
            $factors[] = [
                'name'    => 'no_scan',
                'impact'  => -15,
                'message' => 'No conflict scan has been run yet',
            ];
        } else {
            $last_scan_time = strtotime($latest_scan->completed_at);
            $days_since = (time() - $last_scan_time) / DAY_IN_SECONDS;

            if ($days_since > 30) {
                $score -= 10;
                $factors[] = [
                    'name'    => 'stale_scan',
                    'impact'  => -10,
                    'message' => sprintf('Last scan was %d days ago — run a new scan', (int) $days_since),
                ];
            } elseif ($days_since > 7) {
                $score -= 5;
                $factors[] = [
                    'name'    => 'aging_scan',
                    'impact'  => -5,
                    'message' => sprintf('Last scan was %d days ago', (int) $days_since),
                ];
            }
        }

        // Factor 3: Plugin count risk.
        $active_plugins = get_option('active_plugins', []);
        $plugin_count = count($active_plugins);

        if ($plugin_count > 30) {
            $score -= 10;
            $factors[] = [
                'name'    => 'high_plugin_count',
                'impact'  => -10,
                'message' => sprintf('%d active plugins — high conflict risk', $plugin_count),
            ];
        } elseif ($plugin_count > 20) {
            $score -= 5;
            $factors[] = [
                'name'    => 'moderate_plugin_count',
                'impact'  => -5,
                'message' => sprintf('%d active plugins — moderate conflict risk', $plugin_count),
            ];
        }

        // Factor 4: Pending updates.
        $pending_updates = get_option('jetstrike_cd_pending_updates', []);

        if (count($pending_updates) > 5) {
            $score -= 5;
            $factors[] = [
                'name'    => 'pending_updates',
                'impact'  => -5,
                'message' => sprintf('%d plugin updates pending — update risk', count($pending_updates)),
            ];
        }

        $score = max(0, min(100, $score));

        if ($score >= 90) {
            $grade = 'A';
        } elseif ($score >= 75) {
            $grade = 'B';
        } elseif ($score >= 60) {
            $grade = 'C';
        } elseif ($score >= 40) {
            $grade = 'D';
        } else {
            $grade = 'F';
        }

        return [
            'score'      => $score,
            'grade'      => $grade,
            'factors'    => $factors,
            'checked_at' => current_time('mysql', true),
        ];
    }

    /**
     * Get health data including score, grade, and component breakdown.
     *
     * @return array{score: int, grade: string, factors: array, components: array, checked_at: string}
     */
    public function get_health_data(): array {
        $health = $this->calculate_health_score();

        $components = [];
        foreach ($health['factors'] as $factor) {
            $components[$factor['name']] = [
                'impact'  => $factor['impact'],
                'message' => $factor['message'],
            ];
        }

        $health['components'] = $components;

        return $health;
    }

    /**
     * Register tests with WordPress Site Health.
     *
     * @param array $tests Existing tests.
     * @return array Modified tests.
     */
    public function register_site_health_tests(array $tests): array {
        $tests['direct']['jetstrike_conflict_check'] = [
            'label' => __('Plugin Conflict Detection', 'jetstrike-cd'),
            'test'  => [$this, 'site_health_test'],
        ];

        return $tests;
    }

    /**
     * WordPress Site Health test callback.
     *
     * @return array Site Health test result.
     */
    public function site_health_test(): array {
        $conflict_summary = $this->repository->get_conflict_summary();
        $total = array_sum($conflict_summary);

        if ($conflict_summary['critical'] > 0) {
            return [
                'label'       => __('Critical plugin conflicts detected', 'jetstrike-cd'),
                'status'      => 'critical',
                'badge'       => [
                    'label' => __('Security', 'jetstrike-cd'),
                    'color' => 'red',
                ],
                'description' => sprintf(
                    '<p>%s</p>',
                    sprintf(
                        /* translators: %d: number of critical conflicts */
                        __('Jetstrike Conflict Detector found %d critical plugin conflict(s) that may cause fatal errors or data loss.', 'jetstrike-cd'),
                        $conflict_summary['critical']
                    )
                ),
                'actions'     => sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(admin_url('admin.php?page=jetstrike-cd')),
                    __('View conflicts', 'jetstrike-cd')
                ),
                'test'        => 'jetstrike_conflict_check',
            ];
        }

        if ($total > 0) {
            return [
                'label'       => sprintf(
                    /* translators: %d: total conflicts */
                    __('%d plugin conflict(s) detected', 'jetstrike-cd'),
                    $total
                ),
                'status'      => 'recommended',
                'badge'       => [
                    'label' => __('Performance', 'jetstrike-cd'),
                    'color' => 'orange',
                ],
                'description' => sprintf(
                    '<p>%s</p>',
                    __('Jetstrike Conflict Detector found potential plugin conflicts. Review them to ensure your site runs smoothly.', 'jetstrike-cd')
                ),
                'actions'     => sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(admin_url('admin.php?page=jetstrike-cd')),
                    __('View conflicts', 'jetstrike-cd')
                ),
                'test'        => 'jetstrike_conflict_check',
            ];
        }

        return [
            'label'       => __('No plugin conflicts detected', 'jetstrike-cd'),
            'status'      => 'good',
            'badge'       => [
                'label' => __('Performance', 'jetstrike-cd'),
                'color' => 'blue',
            ],
            'description' => sprintf(
                '<p>%s</p>',
                __('Jetstrike Conflict Detector has not found any plugin conflicts. Your site is running cleanly.', 'jetstrike-cd')
            ),
            'test'        => 'jetstrike_conflict_check',
        ];
    }
}
