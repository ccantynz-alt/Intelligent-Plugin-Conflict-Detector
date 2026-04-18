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

            // Auto-Fix buttons.
            $(document).on('click', '.jetstrike-cd-autofix-btn', this.autoFixConflict.bind(this));
            $(document).on('click', '.jetstrike-cd-revert-btn', this.revertFix.bind(this));

            // License activation.
            $(document).on('click', '#jetstrike-activate-license', this.activateLicense.bind(this));
            $(document).on('click', '#jetstrike-deactivate-license', this.deactivateLicense.bind(this));

            // Toolbar actions.
            $(document).on('click', '#jetstrike-generate-report', this.generateReport.bind(this));
            $(document).on('click', '#jetstrike-export-data', this.exportData.bind(this));
            $(document).on('click', '#jetstrike-toggle-matrix', this.toggleMatrix.bind(this));

            // Health check buttons.
            $(document).on('click', '.jetstrike-cd-health-btn', this.runHealthCheck.bind(this));

            // Import data.
            $(document).on('click', '#jetstrike-import-data', this.importData.bind(this));
            $(document).on('change', '#jetstrike-import-file', this.handleImportFile.bind(this));

            // Pre-update check.
            $(document).on('click', '.jetstrike-cd-preupdate-btn', this.preUpdateCheck.bind(this));

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

                var autoFixCell = '<td class="jetstrike-cd-autofix-cell">';
                if (conflict.can_auto_fix) {
                    autoFixCell += '<button type="button" class="button button-small jetstrike-cd-autofix-btn" ' +
                        'data-conflict-id="' + conflict.id + '" title="' + JetstrikeCD.escapeHtml(conflict.fix_description || '') + '">' +
                        '<span class="dashicons dashicons-admin-generic"></span> Auto-Fix</button>';
                } else {
                    autoFixCell += '<span class="jetstrike-cd-no-fix">&mdash;</span>';
                }
                autoFixCell += '</td>';

                var descriptionHtml = '';
                if (conflict.ai_explanation) {
                    descriptionHtml = '<strong>' + JetstrikeCD.escapeHtml(conflict.ai_explanation) + '</strong>';
                    if (conflict.ai_impact) {
                        descriptionHtml += '<br><span style="color: #b91c1c; font-weight: 600;">' +
                            JetstrikeCD.escapeHtml(conflict.ai_impact) + '</span>';
                    }
                    descriptionHtml += '<br><em style="color: #94a3b8; font-size: 12px;">Technical: ' +
                        JetstrikeCD.escapeHtml(conflict.description) + '</em>';
                } else {
                    descriptionHtml = '<strong>' + JetstrikeCD.escapeHtml(conflict.description) + '</strong>';
                }

                if (conflict.recommendation) {
                    descriptionHtml += '<br><em class="jetstrike-cd-recommendation">' +
                        JetstrikeCD.escapeHtml(conflict.recommendation) + '</em>';
                }

                $table.append(
                    '<tr data-conflict-id="' + conflict.id + '">' +
                    '<td><span class="jetstrike-cd-badge jetstrike-cd-badge--' + conflict.severity + '">' +
                        JetstrikeCD.capitalize(conflict.severity) + '</span></td>' +
                    '<td>' + JetstrikeCD.escapeHtml(conflict.conflict_type.replace(/_/g, ' ')) + '</td>' +
                    '<td>' + descriptionHtml + '</td>' +
                    '<td><code>' + JetstrikeCD.escapeHtml(JetstrikeCD.dirname(conflict.plugin_a)) + '</code>' +
                        pluginB + '</td>' +
                    autoFixCell +
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

        // ── Auto-Fix ─────────────────────────────────────────

        autoFixConflict: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var conflictId = $btn.data('conflict-id');

            if (!confirm('Apply auto-fix for this conflict? A mu-plugin patch will be generated. You can revert this at any time.')) {
                return;
            }

            $btn.prop('disabled', true).html('<span class="jetstrike-cd-spinner"></span> Fixing...');

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_auto_fix',
                    nonce: jetstrikeCD.ajaxNonce,
                    conflict_id: conflictId
                },
                success: function (response) {
                    if (response.success) {
                        JetstrikeCD.showNotice('success', response.data.message);

                        // Replace button with revert button.
                        $btn.replaceWith(
                            '<button type="button" class="button button-small jetstrike-cd-revert-btn" ' +
                            'data-conflict-id="' + conflictId + '">' +
                            '<span class="dashicons dashicons-undo"></span> Revert' +
                            '</button>'
                        );

                        // Update status dropdown to resolved.
                        $btn.closest('tr').find('.jetstrike-cd-conflict-action').val('resolved');

                        // Fade the row to indicate it's resolved.
                        $btn.closest('tr').addClass('jetstrike-cd-row-resolved');
                    } else {
                        $btn.prop('disabled', false).html(
                            '<span class="dashicons dashicons-admin-generic"></span> Auto-Fix'
                        );
                        JetstrikeCD.showNotice('error', response.data.message);
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-admin-generic"></span> Auto-Fix'
                    );
                    JetstrikeCD.showNotice('error', 'Auto-fix failed. Please try again.');
                }
            });
        },

        revertFix: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var conflictId = $btn.data('conflict-id');

            if (!confirm('Revert this auto-fix? The conflict will become active again.')) {
                return;
            }

            $btn.prop('disabled', true).text('Reverting...');

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_revert_fix',
                    nonce: jetstrikeCD.ajaxNonce,
                    conflict_id: conflictId
                },
                success: function (response) {
                    if (response.success) {
                        JetstrikeCD.showNotice('info', response.data.message);

                        // Replace revert button with auto-fix button.
                        $btn.replaceWith(
                            '<button type="button" class="button button-small jetstrike-cd-autofix-btn" ' +
                            'data-conflict-id="' + conflictId + '">' +
                            '<span class="dashicons dashicons-admin-generic"></span> Auto-Fix' +
                            '</button>'
                        );

                        // Update status dropdown to active.
                        $btn.closest('tr').find('.jetstrike-cd-conflict-action').val('active');
                        $btn.closest('tr').removeClass('jetstrike-cd-row-resolved');
                    } else {
                        $btn.prop('disabled', false).html(
                            '<span class="dashicons dashicons-undo"></span> Revert'
                        );
                        JetstrikeCD.showNotice('error', response.data.message);
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-undo"></span> Revert'
                    );
                    JetstrikeCD.showNotice('error', 'Revert failed. Please try again.');
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

        // ── Toolbar Actions ───────────────────────────────────

        generateReport: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            $btn.prop('disabled', true).html('<span class="jetstrike-cd-spinner"></span> Generating...');

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_generate_report',
                    nonce: jetstrikeCD.ajaxNonce
                },
                success: function (response) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-media-document"></span> Generate Report');

                    if (response.success) {
                        // Open report in new window.
                        var win = window.open('', '_blank');
                        win.document.write(response.data.html);
                        win.document.close();
                        JetstrikeCD.showNotice('success', 'Report generated. A new window has opened with the report.');
                    } else {
                        JetstrikeCD.showNotice('error', response.data.message || 'Failed to generate report.');
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-media-document"></span> Generate Report');
                    JetstrikeCD.showNotice('error', 'Failed to generate report.');
                }
            });
        },

        exportData: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            $btn.prop('disabled', true).html('<span class="jetstrike-cd-spinner"></span> Exporting...');

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_export_data',
                    nonce: jetstrikeCD.ajaxNonce,
                    include_scans: true
                },
                success: function (response) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Export Data');

                    if (response.success) {
                        // Trigger file download.
                        var blob = new Blob([response.data.json], { type: 'application/json' });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);

                        JetstrikeCD.showNotice('success',
                            'Exported ' + response.data.stats.conflicts + ' conflict(s) and ' +
                            response.data.stats.plugins + ' plugin(s).');
                    } else {
                        JetstrikeCD.showNotice('error', response.data.message || 'Export failed.');
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Export Data');
                    JetstrikeCD.showNotice('error', 'Export failed.');
                }
            });
        },

        toggleMatrix: function (e) {
            e.preventDefault();
            var $container = $('#jetstrike-matrix-container');
            $container.slideToggle(300);

            var $btn = $(e.currentTarget);
            if ($container.is(':visible')) {
                $btn.addClass('button-primary');
            } else {
                $btn.removeClass('button-primary');
            }
        },

        // ── Health Checks ─────────────────────────────────────

        runHealthCheck: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var checkType = $btn.data('check');
            var actionMap = {
                plugin_health: 'jetstrike_cd_plugin_health',
                db_health: 'jetstrike_cd_db_health',
                php_compat: 'jetstrike_cd_php_compat'
            };
            var labelMap = {
                plugin_health: 'Plugin Health',
                db_health: 'Database Health',
                php_compat: 'PHP Compatibility'
            };

            var action = actionMap[checkType];
            if (!action) return;

            $btn.prop('disabled', true).html('<span class="jetstrike-cd-spinner"></span> Analyzing...');

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    nonce: jetstrikeCD.ajaxNonce
                },
                success: function (response) {
                    $btn.prop('disabled', false).html($btn.html().replace('Analyzing...', labelMap[checkType]));
                    JetstrikeCD.resetHealthButton($btn, checkType, labelMap[checkType]);

                    if (response.success) {
                        var data = response.data;
                        var msg = '';

                        if (checkType === 'plugin_health') {
                            msg = 'Plugin Health: ' + (data.summary.healthy || 0) + ' healthy, ' +
                                (data.summary.abandoned || 0) + ' abandoned, ' +
                                (data.summary.stale || 0) + ' stale, ' +
                                (data.summary.vulnerable || 0) + ' vulnerable. ' +
                                (data.issues ? data.issues.length : 0) + ' total issue(s).';
                        } else if (checkType === 'db_health') {
                            msg = 'Database Health Score: ' + (data.score || 0) + '/100. ' +
                                (data.issues ? data.issues.length : 0) + ' issue(s) found. ' +
                                'Total DB size: ' + (data.stats.total_db_mb || 0) + 'MB.';
                        } else if (checkType === 'php_compat') {
                            msg = 'PHP Compatibility (PHP ' + (data.target_php || '') + '): ' +
                                (data.summary.clean || 0) + ' compatible, ' +
                                (data.summary.warnings || 0) + ' with warnings, ' +
                                (data.summary.errors || 0) + ' incompatible.';
                        }

                        var severity = 'success';
                        if ((data.summary && (data.summary.errors > 0 || data.summary.abandoned > 0 || data.summary.vulnerable > 0)) ||
                            (data.score !== undefined && data.score < 50)) {
                            severity = 'warning';
                        }

                        JetstrikeCD.showNotice(severity, msg);
                    } else {
                        JetstrikeCD.showNotice('error', response.data.message || 'Analysis failed.');
                    }
                },
                error: function () {
                    JetstrikeCD.resetHealthButton($btn, checkType, labelMap[checkType]);
                    JetstrikeCD.showNotice('error', labelMap[checkType] + ' analysis failed.');
                }
            });
        },

        resetHealthButton: function ($btn, checkType, label) {
            var iconMap = {
                plugin_health: 'plugins-checked',
                db_health: 'database',
                php_compat: 'editor-code'
            };
            $btn.prop('disabled', false).html(
                '<span class="dashicons dashicons-' + (iconMap[checkType] || 'admin-generic') + '"></span> ' + label
            );
        },

        // ── Import Data ──────────────────────────────────────

        importData: function (e) {
            e.preventDefault();
            $('#jetstrike-import-file').click();
        },

        handleImportFile: function (e) {
            var file = e.target.files[0];
            if (!file) return;

            var reader = new FileReader();
            reader.onload = function (evt) {
                var json;
                try {
                    json = evt.target.result;
                    JSON.parse(json); // Validate JSON.
                } catch (err) {
                    JetstrikeCD.showNotice('error', 'Invalid JSON file. Please select a valid Jetstrike export file.');
                    return;
                }

                $.ajax({
                    url: jetstrikeCD.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'jetstrike_cd_import_data',
                        nonce: jetstrikeCD.ajaxNonce,
                        import_data: json
                    },
                    success: function (response) {
                        if (response.success) {
                            JetstrikeCD.showNotice('success',
                                'Imported ' + (response.data.imported || 0) + ' conflict(s). ' +
                                (response.data.skipped || 0) + ' duplicate(s) skipped.');
                            setTimeout(function () { window.location.reload(); }, 2000);
                        } else {
                            JetstrikeCD.showNotice('error', response.data.message || 'Import failed.');
                        }
                    },
                    error: function () {
                        JetstrikeCD.showNotice('error', 'Import failed. Please try again.');
                    }
                });
            };
            reader.readAsText(file);

            // Reset the file input so the same file can be re-selected.
            e.target.value = '';
        },

        // ── Pre-Update Check ─────────────────────────────────

        preUpdateCheck: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var pluginFile = $btn.data('plugin');

            if (!pluginFile) return;

            $btn.prop('disabled', true).html('<span class="jetstrike-cd-spinner"></span> Checking...');

            $.ajax({
                url: jetstrikeCD.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jetstrike_cd_pre_update_check',
                    nonce: jetstrikeCD.ajaxNonce,
                    plugin: pluginFile
                },
                success: function (response) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-shield"></span> Pre-Update Check');

                    if (response.success) {
                        var data = response.data;
                        var riskClass = data.risk_level === 'dangerous' ? 'error' :
                                       (data.risk_level === 'risky' ? 'warning' : 'success');
                        var msg = 'Risk Score: ' + data.risk_score + '/100 (' + data.risk_level + '). ' +
                                  (data.new_conflicts || 0) + ' potential new conflict(s) detected.';
                        JetstrikeCD.showNotice(riskClass, msg);
                    } else {
                        JetstrikeCD.showNotice('error', response.data.message || 'Pre-update check failed.');
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-shield"></span> Pre-Update Check');
                    JetstrikeCD.showNotice('error', 'Pre-update check failed.');
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
            settings.autofix_beta_enabled = $form.find('[name="autofix_beta_enabled"]').is(':checked');

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
