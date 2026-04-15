<?php
/**
 * Network Scanner — scans across all sites in a WordPress Multisite network.
 *
 * Agency tier feature. Provides a network-wide view of plugin conflicts,
 * showing which sites have issues and which plugins are causing the most
 * problems across the network.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Multisite;

use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Scanner\ScanEngine;
use Jetstrike\ConflictDetector\Subscription\FeatureFlags;

final class NetworkScanner {

    /** @var Repository */
    private Repository $repository;

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Check if multisite scanning is available.
     */
    public static function is_available(): bool {
        return is_multisite() && FeatureFlags::can('multisite_support');
    }

    /**
     * Get a network-wide overview of all sites and their conflict status.
     *
     * @return array Network overview data.
     */
    public function get_network_overview(): array {
        if (! is_multisite()) {
            return ['error' => 'Not a multisite installation.'];
        }

        $sites = get_sites(['number' => 200]);
        $overview = [
            'total_sites'       => count($sites),
            'sites_with_issues' => 0,
            'total_conflicts'   => 0,
            'network_plugins'   => $this->get_network_plugin_stats(),
            'sites'             => [],
        ];

        foreach ($sites as $site) {
            switch_to_blog((int) $site->blog_id);

            $site_data = $this->scan_site((int) $site->blog_id);
            $overview['sites'][] = $site_data;

            if ($site_data['conflict_count'] > 0) {
                $overview['sites_with_issues']++;
                $overview['total_conflicts'] += $site_data['conflict_count'];
            }

            restore_current_blog();
        }

        // Sort sites by conflict count (worst first).
        usort($overview['sites'], function (array $a, array $b): int {
            return $b['conflict_count'] <=> $a['conflict_count'];
        });

        return $overview;
    }

    /**
     * Run a quick scan on a specific site.
     *
     * @param int $blog_id Blog ID to scan.
     * @return array Scan results for the site.
     */
    public function scan_site(int $blog_id): array {
        $site_url = get_site_url($blog_id);
        $site_name = get_bloginfo('name');
        $active_plugins = get_option('active_plugins', []);

        // Filter out Jetstrike itself.
        $plugins = array_values(array_filter($active_plugins, function (string $p): bool {
            return $p !== JETSTRIKE_CD_BASENAME;
        }));

        // Check for existing conflicts in this site's database.
        $conflicts = $this->repository->list_active_conflicts(1, 100);
        $summary = $this->repository->get_conflict_summary();

        return [
            'blog_id'        => $blog_id,
            'url'            => $site_url,
            'name'           => $site_name,
            'plugin_count'   => count($plugins),
            'conflict_count' => array_sum($summary),
            'critical_count' => $summary['critical'] ?? 0,
            'high_count'     => $summary['high'] ?? 0,
            'plugins'        => array_map('dirname', $plugins),
            'last_scan'      => $this->get_last_scan_date(),
            'health_grade'   => $this->get_quick_grade($summary),
        ];
    }

    /**
     * Run a network-wide scan across all sites.
     *
     * @return array Results per site.
     */
    public function scan_network(): array {
        if (! is_multisite()) {
            return ['error' => 'Not a multisite installation.'];
        }

        $sites = get_sites(['number' => 200]);
        $results = [];

        foreach ($sites as $site) {
            switch_to_blog((int) $site->blog_id);

            $plugins = get_option('active_plugins', []);
            $plugins = array_values(array_filter($plugins, function (string $p): bool {
                return $p !== JETSTRIKE_CD_BASENAME;
            }));

            if (count($plugins) >= 2) {
                $engine = new ScanEngine($this->repository);
                $conflicts = $engine->run_static_analysis($plugins);

                $results[] = [
                    'blog_id'    => (int) $site->blog_id,
                    'url'        => get_site_url(),
                    'name'       => get_bloginfo('name'),
                    'plugins'    => count($plugins),
                    'conflicts'  => $conflicts,
                    'count'      => count($conflicts),
                ];
            }

            restore_current_blog();
        }

        return $results;
    }

    /**
     * Find plugins causing the most conflicts across the network.
     *
     * @return array Plugin conflict frequency data.
     */
    public function get_most_problematic_plugins(): array {
        $overview = $this->get_network_overview();
        $plugin_problems = [];

        foreach ($overview['sites'] as $site) {
            // We'd need to query each site's conflicts for this data.
            // For now, just count active conflicts per plugin.
        }

        return $plugin_problems;
    }

    /**
     * Get network-activated plugin statistics.
     */
    private function get_network_plugin_stats(): array {
        $network_plugins = get_site_option('active_sitewide_plugins', []);

        return [
            'network_active_count' => count($network_plugins),
            'network_plugins'      => array_map('dirname', array_keys($network_plugins)),
        ];
    }

    /**
     * Get the date of the last scan on the current site.
     */
    private function get_last_scan_date(): ?string {
        $latest = $this->repository->get_latest_scan();
        return $latest ? ($latest->completed_at ?? $latest->created_at) : null;
    }

    /**
     * Calculate a quick health grade from a conflict summary.
     */
    private function get_quick_grade(array $summary): string {
        $critical = $summary['critical'] ?? 0;
        $high = $summary['high'] ?? 0;
        $medium = $summary['medium'] ?? 0;
        $total = array_sum($summary);

        if ($total === 0) {
            return 'A';
        }

        if ($critical > 0) {
            return 'F';
        }

        if ($high > 2) {
            return 'D';
        }

        if ($high > 0 || $medium > 5) {
            return 'C';
        }

        if ($medium > 0) {
            return 'B';
        }

        return 'A';
    }
}
