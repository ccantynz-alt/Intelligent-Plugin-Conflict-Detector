/**
 * Jetstrike Conflict Detector — Admin Dashboard JavaScript
 *
 * @package Jetstrike\ConflictDetector
 */

(function ($) {
    'use strict';

    var JetstrikeCD = {
        pollInterval: null,
        currentScanId: null,

        init: function () {
            this.bindEvents();
            this.checkForRunningScans();
        },

        bindEvents: function () {
            // Scan buttons.
            $(document).on('click', '.jetstrike-cd-scan-btn', this.startScan.bind(this));
            $(document).on('click', '.jetstrike-cd-cancel-scan', this.cancelScan.bind(this));

            // Conflict status changes.
            $(document).on('change', '.jetstrike-cd-conflict-action', this.updateConflictStatus.bind(this));

            // License activation.
            $(document).on('click', '#jetstrike-activate-license', this.activateLicense.bind(this));
            $(document).on('click', '#jetstrike-deactivate-license', this.deactivateLicense.bind(this));

            // Settings form.
            $(document).on('click', '#jetstrike-save-settings', this.saveSettings.bind(this));

            // Notice dismissal.
            $(document).on('click', '.jetstrike-cd-notice .notice-dismiss', this.dismissNotice.bind(this));
        },

        // ── Scanning ──────────────────────────────────────────

        startScan: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var scanType = $btn.data('scan-type');

            // Disable all scan buttons.
            $('.jetstrike-cd-scan-btn').prop('disabled', true);

            // Show progress indicator.
            $btn.html('<span class="jetstrike-cd-spinner"></span> ' + jetstrikeCD.strings.scanning);

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_start_scan',
                    nonce: jetstrikeCD.ajaxNonce,
                    scan_type: scanType
                },
                success: function (response) {
                    if (response.success) {
                        JetstrikeCD.currentScanId = response.data.scan_id;
                        JetstrikeCD.showProgress();
                        JetstrikeCD.startPolling();
                    } else {
                        JetstrikeCD.showNotice('error', response.data.message || jetstrikeCD.strings.scanFailed);
                        JetstrikeCD.resetButtons();
                    }
                },
                error: function () {
                    JetstrikeCD.showNotice('error', jetstrikeCD.strings.scanFailed);
                    JetstrikeCD.resetButtons();
                }
            });
        },

        cancelScan: function (e) {
            e.preventDefault();

            if (!this.currentScanId) return;

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_cancel_scan',
                    nonce: jetstrikeCD.ajaxNonce,
                    scan_id: this.currentScanId
                },
                success: function () {
                    JetstrikeCD.stopPolling();
                    JetstrikeCD.hideProgress();
                    JetstrikeCD.resetButtons();
                    JetstrikeCD.showNotice('info', 'Scan cancelled.');
                }
            });
        },

        startPolling: function () {
            this.stopPolling();
            this.pollInterval = setInterval(this.pollStatus.bind(this), 3000);
        },

        stopPolling: function () {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        },

        pollStatus: function () {
            if (!this.currentScanId) return;

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_scan_status',
                    nonce: jetstrikeCD.ajaxNonce,
                    scan_id: this.currentScanId
                },
                success: function (response) {
                    if (!response.success) return;

                    var data = response.data;

                    if (data.status === 'completed') {
                        JetstrikeCD.stopPolling();
                        JetstrikeCD.onScanComplete(data);
                    } else if (data.status === 'failed' || data.status === 'cancelled') {
                        JetstrikeCD.stopPolling();
                        JetstrikeCD.hideProgress();
                        JetstrikeCD.resetButtons();
                        JetstrikeCD.showNotice('error', 'Scan ' + data.status + '.');
                    } else {
                        JetstrikeCD.updateProgress(50);
                    }
                }
            });
        },

        onScanComplete: function (data) {
            this.hideProgress();
            this.resetButtons();

            var count = data.conflicts_found || 0;

            if (count === 0) {
                this.showNotice('success', jetstrikeCD.strings.noConflicts);
            } else {
                this.showNotice('warning',
                    jetstrikeCD.strings.scanComplete + ' ' + count + ' conflict(s) found.');

                // Render conflicts if we have them.
                if (data.conflicts && data.conflicts.length > 0) {
                    this.renderConflicts(data.conflicts);
                }
            }

            // Refresh the page after a short delay to update all widgets.
            setTimeout(function () {
                window.location.reload();
            }, 2000);
        },

        renderConflicts: function (conflicts) {
            var $table = $('.jetstrike-cd-table tbody');
            if (!$table.length) return;

            $table.empty();

            conflicts.forEach(function (conflict) {
                var pluginB = conflict.plugin_b
                    ? '<br>vs <code>' + JetstrikeCD.escapeHtml(JetstrikeCD.dirname(conflict.plugin_b)) + '</code>'
                    : '';

                $table.append(
                    '<tr data-conflict-id="' + conflict.id + '">' +
                    '<td><span class="jetstrike-cd-badge jetstrike-cd-badge--' + conflict.severity + '">' +
                        JetstrikeCD.capitalize(conflict.severity) + '</span></td>' +
                    '<td>' + JetstrikeCD.escapeHtml(conflict.conflict_type.replace(/_/g, ' ')) + '</td>' +
                    '<td><strong>' + JetstrikeCD.escapeHtml(conflict.description) + '</strong>' +
                        (conflict.recommendation ? '<br><em class="jetstrike-cd-recommendation">' +
                        JetstrikeCD.escapeHtml(conflict.recommendation) + '</em>' : '') + '</td>' +
                    '<td><code>' + JetstrikeCD.escapeHtml(JetstrikeCD.dirname(conflict.plugin_a)) + '</code>' +
                        pluginB + '</td>' +
                    '<td><select class="jetstrike-cd-conflict-action" data-conflict-id="' + conflict.id + '">' +
                        '<option value="active" selected>Active</option>' +
                        '<option value="resolved">Mark Resolved</option>' +
                        '<option value="ignored">Ignore</option>' +
                        '<option value="false_positive">False Positive</option>' +
                    '</select></td>' +
                    '</tr>'
                );
            });
        },

        showProgress: function () {
            var $progress = $('#jetstrike-scan-progress');

            if (!$progress.length) {
                var html = '<div class="jetstrike-cd-scan-progress" id="jetstrike-scan-progress">' +
                    '<div class="jetstrike-cd-progress-bar"><div class="jetstrike-cd-progress-fill" style="width: 20%"></div></div>' +
                    '<p class="jetstrike-cd-progress-text">' + jetstrikeCD.strings.scanning + '</p>' +
                    '<button type="button" class="button button-link-delete jetstrike-cd-cancel-scan">Cancel</button>' +
                    '</div>';

                $('.jetstrike-cd-card--actions').append(html);
            } else {
                $progress.show();
            }
        },

        hideProgress: function () {
            $('#jetstrike-scan-progress').remove();
        },

        updateProgress: function (percent) {
            $('.jetstrike-cd-progress-fill').css('width', percent + '%');
        },

        resetButtons: function () {
            $('.jetstrike-cd-scan-btn').prop('disabled', false);
            $('.jetstrike-cd-scan-btn[data-scan-type="quick"]').html(
                '<span class="dashicons dashicons-search"></span> Quick Scan'
            );
            $('.jetstrike-cd-scan-btn[data-scan-type="full"]').html(
                '<span class="dashicons dashicons-admin-tools"></span> Full Scan'
            );
        },

        checkForRunningScans: function () {
            // Check if there is a running scan we should poll for.
            var $progress = $('#jetstrike-scan-progress');
            if ($progress.length) {
                // Find scan ID from the page data or just poll status endpoint.
                $.ajax({
                    url: jetstrikeCD.restUrl + 'status',
                    headers: { 'X-WP-Nonce': jetstrikeCD.nonce },
                    success: function (data) {
                        if (data.has_running_scan && data.last_scan) {
                            JetstrikeCD.currentScanId = data.last_scan.id;
                            JetstrikeCD.startPolling();
                        }
                    }
                });
            }
        },

        // ── Conflict Management ───────────────────────────────

        updateConflictStatus: function (e) {
            var $select = $(e.currentTarget);
            var conflictId = $select.data('conflict-id');
            var newStatus = $select.val();

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_update_conflict',
                    nonce: jetstrikeCD.ajaxNonce,
                    conflict_id: conflictId,
                    status: newStatus
                },
                success: function (response) {
                    if (response.success) {
                        if (newStatus !== 'active') {
                            $select.closest('tr').fadeOut(300, function () {
                                $(this).remove();
                            });
                        }
                    }
                }
            });
        },

        // ── License ───────────────────────────────────────────

        activateLicense: function (e) {
            e.preventDefault();
            var licenseKey = $('#jetstrike-license-key').val().trim();

            if (!licenseKey) {
                $('#jetstrike-license-message').text('Please enter a license key.').css('color', '#dc3545');
                return;
            }

            var $btn = $(e.currentTarget);
            $btn.prop('disabled', true).text('Activating...');

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_activate_license',
                    nonce: jetstrikeCD.ajaxNonce,
                    license_key: licenseKey
                },
                success: function (response) {
                    $btn.prop('disabled', false).text('Activate');

                    if (response.success) {
                        $('#jetstrike-license-message').text(response.data.message).css('color', '#28a745');
                        setTimeout(function () { window.location.reload(); }, 1500);
                    } else {
                        $('#jetstrike-license-message').text(response.data.message).css('color', '#dc3545');
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).text('Activate');
                    $('#jetstrike-license-message').text('Connection error. Please try again.').css('color', '#dc3545');
                }
            });
        },

        deactivateLicense: function (e) {
            e.preventDefault();

            if (!confirm(jetstrikeCD.strings.confirm)) return;

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_deactivate_license',
                    nonce: jetstrikeCD.ajaxNonce
                },
                success: function () {
                    window.location.reload();
                }
            });
        },

        // ── Settings ──────────────────────────────────────────

        saveSettings: function (e) {
            e.preventDefault();

            var $form = $('#jetstrike-settings-form');
            var settings = {};

            // Checkboxes.
            settings.auto_scan_enabled = $form.find('[name="auto_scan_enabled"]').is(':checked');
            settings.email_alerts = $form.find('[name="email_alerts"]').is(':checked');

            // Text/select fields.
            settings.scan_frequency = $form.find('[name="scan_frequency"]').val();
            settings.alert_email = $form.find('[name="alert_email"]').val();
            settings.slack_webhook_url = $form.find('[name="slack_webhook_url"]').val();
            settings.scan_timeout = parseInt($form.find('[name="scan_timeout"]').val(), 10);
            settings.performance_threshold = parseFloat($form.find('[name="performance_threshold"]').val());

            // Excluded plugins.
            settings.excluded_plugins = [];
            $form.find('[name="excluded_plugins[]"]:checked').each(function () {
                settings.excluded_plugins.push($(this).val());
            });

            var $btn = $('#jetstrike-save-settings');
            $btn.prop('disabled', true).val('Saving...');

            $.ajax({
                url: jetstrikeCD.restUrl + 'settings',
                method: 'POST',
                headers: { 'X-WP-Nonce': jetstrikeCD.nonce },
                contentType: 'application/json',
                data: JSON.stringify(settings),
                success: function () {
                    $btn.prop('disabled', false).val('Save Settings');
                    JetstrikeCD.showNotice('success', 'Settings saved successfully.');
                },
                error: function () {
                    $btn.prop('disabled', false).val('Save Settings');
                    JetstrikeCD.showNotice('error', 'Failed to save settings.');
                }
            });
        },

        // ── Notices ───────────────────────────────────────────

        dismissNotice: function (e) {
            var $notice = $(e.currentTarget).closest('.jetstrike-cd-notice');
            var noticeId = $notice.data('notice-id');

            if (noticeId) {
                $.post(jetstrikeCD.ajaxUrl, {
                    action: 'jetstrike_cd_dismiss_notice',
                    nonce: jetstrikeCD.ajaxNonce,
                    notice_id: noticeId
                });
            }
        },

        showNotice: function (type, message) {
            var wpType = type === 'error' ? 'error' : (type === 'warning' ? 'warning' : (type === 'info' ? 'info' : 'success'));

            var $notice = $(
                '<div class="notice notice-' + wpType + ' is-dismissible jetstrike-cd-notice">' +
                '<p><strong>Jetstrike:</strong> ' + this.escapeHtml(message) + '</p>' +
                '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>' +
                '</div>'
            );

            $('.jetstrike-cd-wrap h1').after($notice);

            // Auto-dismiss success notices.
            if (type === 'success') {
                setTimeout(function () {
                    $notice.fadeOut(300, function () { $(this).remove(); });
                }, 5000);
            }
        },

        // ── Utilities ─────────────────────────────────────────

        escapeHtml: function (str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        },

        capitalize: function (str) {
            return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
        },

        dirname: function (path) {
            if (!path) return '';
            var parts = path.split('/');
            return parts.length > 1 ? parts[0] : path;
        }
    };

    $(document).ready(function () {
        JetstrikeCD.init();
    });

})(jQuery);
