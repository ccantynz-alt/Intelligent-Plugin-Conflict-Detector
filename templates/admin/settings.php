<?php
/**
 * Settings page template.
 *
 * @var array  $settings     Plugin settings.
 * @var array  $license_info License information.
 * @var string $tier         Current license tier.
 * @var array  $active_plugins Active plugins list.
 *
 * @package Jetstrike\ConflictDetector
 */

defined('ABSPATH') || exit;

$frequencies = [
    'hourly'              => __('Every Hour', 'jetstrike-cd'),
    'jetstrike_six_hours' => __('Every 6 Hours', 'jetstrike-cd'),
    'twicedaily'          => __('Twice Daily', 'jetstrike-cd'),
    'daily'               => __('Once Daily', 'jetstrike-cd'),
];
?>

<div class="wrap jetstrike-cd-wrap">
    <h1>
        <span class="dashicons dashicons-shield"></span>
        <?php esc_html_e('Conflict Detector Settings', 'jetstrike-cd'); ?>
    </h1>

    <!-- License Section -->
    <div class="jetstrike-cd-card">
        <h2><?php esc_html_e('License', 'jetstrike-cd'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Current Plan', 'jetstrike-cd'); ?></th>
                <td>
                    <span class="jetstrike-cd-badge jetstrike-cd-badge--<?php echo esc_attr($tier); ?>">
                        <?php echo esc_html(ucfirst($tier)); ?>
                    </span>
                    <?php if ($license_info['status'] === 'active'): ?>
                        <span class="jetstrike-cd-license-status jetstrike-cd-license-status--active">
                            <?php esc_html_e('Active', 'jetstrike-cd'); ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="jetstrike-license-key"><?php esc_html_e('License Key', 'jetstrike-cd'); ?></label>
                </th>
                <td>
                    <input type="text" id="jetstrike-license-key" class="regular-text"
                        value="<?php echo esc_attr($license_info['key']); ?>"
                        placeholder="<?php esc_attr_e('Enter your license key', 'jetstrike-cd'); ?>">
                    <button type="button" class="button" id="jetstrike-activate-license">
                        <?php echo $license_info['status'] === 'active'
                            ? esc_html__('Update', 'jetstrike-cd')
                            : esc_html__('Activate', 'jetstrike-cd'); ?>
                    </button>
                    <?php if ($license_info['status'] === 'active'): ?>
                        <button type="button" class="button button-link-delete" id="jetstrike-deactivate-license">
                            <?php esc_html_e('Deactivate', 'jetstrike-cd'); ?>
                        </button>
                    <?php endif; ?>
                    <p class="description" id="jetstrike-license-message"></p>
                </td>
            </tr>
        </table>
    </div>

    <!-- Scan Settings -->
    <div class="jetstrike-cd-card">
        <h2><?php esc_html_e('Scan Settings', 'jetstrike-cd'); ?></h2>
        <form id="jetstrike-settings-form">
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Automated Background Scans', 'jetstrike-cd'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_scan_enabled" value="1"
                                <?php checked(! empty($settings['auto_scan_enabled'])); ?>
                                <?php echo $tier === 'free' ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Enable automated background scanning', 'jetstrike-cd'); ?>
                        </label>
                        <?php if ($tier === 'free'): ?>
                            <p class="description jetstrike-cd-pro-badge"><?php esc_html_e('Pro feature', 'jetstrike-cd'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="scan_frequency"><?php esc_html_e('Scan Frequency', 'jetstrike-cd'); ?></label>
                    </th>
                    <td>
                        <select name="scan_frequency" id="scan_frequency" <?php echo $tier === 'free' ? 'disabled' : ''; ?>>
                            <?php foreach ($frequencies as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['scan_frequency'] ?? 'twicedaily', $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="performance_threshold"><?php esc_html_e('Performance Threshold', 'jetstrike-cd'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="performance_threshold" id="performance_threshold"
                            value="<?php echo esc_attr($settings['performance_threshold'] ?? '3.0'); ?>"
                            min="1.5" max="10" step="0.5" class="small-text">
                        <p class="description">
                            <?php esc_html_e('Flag plugins that increase load time by this multiplier (e.g., 3.0 = 3x slower).', 'jetstrike-cd'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="scan_timeout"><?php esc_html_e('Scan Timeout (seconds)', 'jetstrike-cd'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="scan_timeout" id="scan_timeout"
                            value="<?php echo (int) ($settings['scan_timeout'] ?? 30); ?>"
                            min="10" max="120" class="small-text">
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Notifications', 'jetstrike-cd'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Email Alerts', 'jetstrike-cd'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="email_alerts" value="1"
                                <?php checked(! empty($settings['email_alerts'])); ?>
                                <?php echo $tier === 'free' ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Send email when conflicts are detected', 'jetstrike-cd'); ?>
                        </label>
                        <?php if ($tier === 'free'): ?>
                            <p class="description jetstrike-cd-pro-badge"><?php esc_html_e('Pro feature', 'jetstrike-cd'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="alert_email"><?php esc_html_e('Alert Email', 'jetstrike-cd'); ?></label>
                    </th>
                    <td>
                        <input type="email" name="alert_email" id="alert_email" class="regular-text"
                            value="<?php echo esc_attr($settings['alert_email'] ?? get_option('admin_email')); ?>"
                            <?php echo $tier === 'free' ? 'disabled' : ''; ?>>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="slack_webhook_url"><?php esc_html_e('Slack Webhook URL', 'jetstrike-cd'); ?></label>
                    </th>
                    <td>
                        <input type="url" name="slack_webhook_url" id="slack_webhook_url" class="regular-text"
                            value="<?php echo esc_attr($settings['slack_webhook_url'] ?? ''); ?>"
                            placeholder="https://hooks.slack.com/services/..."
                            <?php echo $tier !== 'agency' ? 'disabled' : ''; ?>>
                        <?php if ($tier !== 'agency'): ?>
                            <p class="description jetstrike-cd-pro-badge"><?php esc_html_e('Agency feature', 'jetstrike-cd'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Auto-Fix Engine (Beta)', 'jetstrike-cd'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Auto-Fix', 'jetstrike-cd'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="autofix_beta_enabled" value="1"
                                <?php checked(get_option('jetstrike_cd_autofix_beta_enabled', 'no'), 'yes'); ?>
                                <?php echo $tier === 'free' ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Enable the Auto-Fix Engine (beta)', 'jetstrike-cd'); ?>
                        </label>
                        <?php if ($tier === 'free'): ?>
                            <p class="description jetstrike-cd-pro-badge"><?php esc_html_e('Pro feature', 'jetstrike-cd'); ?></p>
                        <?php else: ?>
                            <p class="description" style="color: #b45309;">
                                <?php esc_html_e('Warning: Auto-Fix writes mu-plugin patch files to resolve conflicts. Each patch is individually reversible. A health check runs automatically after every fix — if your site becomes unreachable, the patch is removed immediately.', 'jetstrike-cd'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Excluded Plugins', 'jetstrike-cd'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Skip These Plugins', 'jetstrike-cd'); ?></th>
                    <td>
                        <fieldset>
                            <?php
                            $excluded = $settings['excluded_plugins'] ?? [];

                            foreach ($active_plugins as $plugin_file):
                                if ($plugin_file === JETSTRIKE_CD_BASENAME) {
                                    continue;
                                }

                                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
                                $name = ! empty($plugin_data['Name']) ? $plugin_data['Name'] : dirname($plugin_file);
                            ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="excluded_plugins[]"
                                    value="<?php echo esc_attr($plugin_file); ?>"
                                    <?php checked(in_array($plugin_file, $excluded, true)); ?>>
                                <?php echo esc_html($name); ?>
                            </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('Selected plugins will be excluded from conflict scans.', 'jetstrike-cd'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save Settings', 'jetstrike-cd'), 'primary', 'jetstrike-save-settings'); ?>
        </form>
    </div>
</div>
