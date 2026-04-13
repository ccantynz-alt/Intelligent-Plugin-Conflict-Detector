/**
 * Admin JavaScript for Intelligent Plugin Conflict Detector.
 *
 * @package IntelligentPluginConflictDetector
 */

/* global jQuery, ipcdAdmin, wp */
(function ($) {
	'use strict';

	var IPCD = {
		/**
		 * Initialize the admin interface.
		 */
		init: function () {
			this.bindEvents();
			this.loadCurrentPage();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function () {
			// Scan buttons.
			$(document).on('click', '.ipcd-btn-scan', this.handleScan.bind(this));

			// Filter button.
			$('#ipcd-filter-apply').on('click', this.loadConflicts.bind(this));

			// Settings form.
			$('#ipcd-settings-form').on('submit', this.handleSaveSettings.bind(this));

			// Resolve conflict.
			$(document).on(
				'click',
				'.ipcd-btn-resolve',
				this.handleResolveConflict.bind(this)
			);
		},

		/**
		 * Load data for the current page.
		 */
		loadCurrentPage: function () {
			var page = this.getCurrentPage();

			switch (page) {
				case 'ipcd-dashboard':
					this.loadDashboardStats();
					this.loadRecentConflicts();
					break;
				case 'ipcd-conflicts':
					this.loadConflicts();
					break;
				case 'ipcd-scans':
					this.loadScans();
					break;
				case 'ipcd-settings':
					this.loadSettings();
					break;
			}
		},

		/**
		 * Get the current admin page identifier.
		 *
		 * @return {string} Page identifier.
		 */
		getCurrentPage: function () {
			var params = new URLSearchParams(window.location.search);
			return params.get('page') || 'ipcd-dashboard';
		},

		/**
		 * Make an API request.
		 *
		 * @param {string} endpoint API endpoint path.
		 * @param {Object} options  Request options.
		 * @return {Promise} jQuery promise.
		 */
		apiRequest: function (endpoint, options) {
			var defaults = {
				url: ipcdAdmin.restUrl + '/' + endpoint,
				method: 'GET',
				beforeSend: function (xhr) {
					xhr.setRequestHeader('X-WP-Nonce', ipcdAdmin.nonce);
				},
			};

			return $.ajax($.extend(defaults, options));
		},

		/**
		 * Load dashboard statistics.
		 */
		loadDashboardStats: function () {
			this.apiRequest('stats')
				.done(function (data) {
					$('#ipcd-stat-total').text(data.total || 0);
					$('#ipcd-stat-active').text(data.active || 0);
					$('#ipcd-stat-critical').text(data.critical || 0);
					$('#ipcd-stat-resolved').text(data.resolved || 0);
					$('#ipcd-last-scan').text(
						data.last_scan
							? 'Last scan: ' + data.last_scan
							: 'Last scan: Never'
					);
					$('#ipcd-active-plugins').text(
						'Active plugins: ' + (data.active_plugins || 0)
					);
					$('#ipcd-monitoring-status').text(
						'Monitoring: ' +
							(data.monitoring_active ? 'Enabled' : 'Disabled')
					);
				})
				.fail(function () {
					$('#ipcd-stat-total').text('?');
					$('#ipcd-stat-active').text('?');
					$('#ipcd-stat-critical').text('?');
					$('#ipcd-stat-resolved').text('?');
				});
		},

		/**
		 * Load recent conflicts for the dashboard.
		 */
		loadRecentConflicts: function () {
			this.apiRequest('conflicts', {
				data: { per_page: 10, resolved: '0' },
			})
				.done(function (data) {
					IPCD.renderConflictsTable(
						'#ipcd-conflicts-body',
						data,
						'dashboard'
					);
				})
				.fail(function () {
					$('#ipcd-conflicts-body').html(
						'<tr><td colspan="6" class="ipcd-no-data">Failed to load conflicts.</td></tr>'
					);
				});
		},

		/**
		 * Load all conflicts with filters.
		 */
		loadConflicts: function () {
			var severity = $('#ipcd-filter-severity').val();
			var resolved = $('#ipcd-filter-status').val();

			this.apiRequest('conflicts', {
				data: {
					per_page: 50,
					severity: severity,
					resolved: resolved,
				},
			})
				.done(function (data) {
					IPCD.renderConflictsTable(
						'#ipcd-all-conflicts-body',
						data,
						'full'
					);
				})
				.fail(function () {
					$('#ipcd-all-conflicts-body').html(
						'<tr><td colspan="8" class="ipcd-no-data">Failed to load conflicts.</td></tr>'
					);
				});
		},

		/**
		 * Load scan history.
		 */
		loadScans: function () {
			this.apiRequest('scans')
				.done(function (data) {
					IPCD.renderScansTable(data);
				})
				.fail(function () {
					$('#ipcd-scans-body').html(
						'<tr><td colspan="8" class="ipcd-no-data">Failed to load scan history.</td></tr>'
					);
				});
		},

		/**
		 * Load settings.
		 */
		loadSettings: function () {
			this.apiRequest('settings')
				.done(function (data) {
					$('#ipcd-setting-monitoring').prop(
						'checked',
						data.monitoring_enabled
					);
					$('#ipcd-setting-frequency').val(data.scan_frequency);
					$('#ipcd-setting-threshold').val(data.error_threshold);
					$('#ipcd-setting-email-enabled').prop(
						'checked',
						data.email_notifications
					);
					$('#ipcd-setting-email').val(data.notification_email);
				})
				.fail(function () {
					$('#ipcd-settings-status')
						.text('Failed to load settings.')
						.addClass('ipcd-status-error');
				});
		},

		/**
		 * Handle scan button click.
		 *
		 * @param {Event} e Click event.
		 */
		handleScan: function (e) {
			e.preventDefault();

			var $btn = $(e.currentTarget);
			var scanType = $btn.data('scan-type');
			var $status = $('#ipcd-scan-status');

			if (
				scanType === 'full' &&
				!window.confirm(ipcdAdmin.strings.confirmScan)
			) {
				return;
			}

			// Disable all scan buttons.
			$('.ipcd-btn-scan').prop('disabled', true);
			$status
				.text(ipcdAdmin.strings.scanning)
				.removeClass()
				.addClass('ipcd-scan-status ipcd-status-running');

			this.apiRequest('scan', {
				method: 'POST',
				data: { type: scanType },
			})
				.done(
					function () {
						$status
							.text(ipcdAdmin.strings.scanComplete)
							.removeClass()
							.addClass('ipcd-scan-status ipcd-status-success');

						// Reload the dashboard data.
						this.loadCurrentPage();
					}.bind(this)
				)
				.fail(function () {
					$status
						.text(ipcdAdmin.strings.scanFailed)
						.removeClass()
						.addClass('ipcd-scan-status ipcd-status-error');
				})
				.always(function () {
					$('.ipcd-btn-scan').prop('disabled', false);
				});
		},

		/**
		 * Handle resolve conflict button click.
		 *
		 * @param {Event} e Click event.
		 */
		handleResolveConflict: function (e) {
			e.preventDefault();

			var $btn = $(e.currentTarget);
			var conflictId = $btn.data('conflict-id');

			$btn.prop('disabled', true).text(ipcdAdmin.strings.resolving);

			this.apiRequest('conflicts/' + conflictId + '/resolve', {
				method: 'POST',
			})
				.done(
					function () {
						$btn.text(ipcdAdmin.strings.resolved)
							.addClass('ipcd-status-resolved')
							.closest('tr')
							.fadeOut(300, function () {
								$(this).remove();
							});

						// Refresh stats.
						if (this.getCurrentPage() === 'ipcd-dashboard') {
							this.loadDashboardStats();
						}
					}.bind(this)
				)
				.fail(function () {
					$btn.prop('disabled', false).text('Resolve');
				});
		},

		/**
		 * Handle settings form submission.
		 *
		 * @param {Event} e Submit event.
		 */
		handleSaveSettings: function (e) {
			e.preventDefault();

			var $status = $('#ipcd-settings-status');
			var $btn = $('#ipcd-save-settings');

			$btn.prop('disabled', true);
			$status
				.text(ipcdAdmin.strings.savingSettings)
				.removeClass()
				.addClass('ipcd-settings-status');

			var data = {
				monitoring_enabled: $('#ipcd-setting-monitoring').is(':checked'),
				scan_frequency: $('#ipcd-setting-frequency').val(),
				error_threshold: $('#ipcd-setting-threshold').val(),
				email_notifications: $('#ipcd-setting-email-enabled').is(
					':checked'
				),
				notification_email: $('#ipcd-setting-email').val(),
			};

			this.apiRequest('settings', {
				method: 'POST',
				data: data,
			})
				.done(function () {
					$status
						.text(ipcdAdmin.strings.settingsSaved)
						.addClass('ipcd-status-success');
				})
				.fail(function () {
					$status
						.text('Failed to save settings.')
						.addClass('ipcd-status-error');
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		},

		/**
		 * Render conflicts into a table body.
		 *
		 * @param {string} selector Table body selector.
		 * @param {Array}  data     Conflicts data.
		 * @param {string} mode     'dashboard' or 'full'.
		 */
		renderConflictsTable: function (selector, data, mode) {
			var $body = $(selector);

			if (!data || data.length === 0) {
				var colSpan = mode === 'dashboard' ? 6 : 8;
				$body.html(
					'<tr><td colspan="' +
						colSpan +
						'" class="ipcd-no-data">No conflicts found. Your plugins are working well together! 🎉</td></tr>'
				);
				return;
			}

			var html = '';

			data.forEach(function (conflict) {
				html += '<tr>';
				html +=
					'<td><span class="ipcd-severity ipcd-severity-' +
					IPCD.escapeHtml(conflict.severity) +
					'">' +
					IPCD.escapeHtml(conflict.severity) +
					'</span></td>';

				if (mode === 'dashboard') {
					html +=
						'<td><strong>' +
						IPCD.escapeHtml(conflict.plugin_slug) +
						'</strong> ↔ <strong>' +
						IPCD.escapeHtml(conflict.conflicting_plugin_slug) +
						'</strong></td>';
				} else {
					html +=
						'<td><strong>' +
						IPCD.escapeHtml(conflict.plugin_slug) +
						'</strong></td>';
					html +=
						'<td><strong>' +
						IPCD.escapeHtml(conflict.conflicting_plugin_slug) +
						'</strong></td>';
				}

				html +=
					'<td><span class="ipcd-conflict-type">' +
					IPCD.formatConflictType(conflict.conflict_type) +
					'</span></td>';
				html +=
					'<td class="ipcd-error-message">' +
					IPCD.escapeHtml(conflict.error_message) +
					'</td>';
				html +=
					'<td>' +
					IPCD.escapeHtml(conflict.detected_at || '') +
					'</td>';

				if (mode === 'full') {
					var statusClass = conflict.resolved === '1' ? 'resolved' : 'active';
					var statusLabel = conflict.resolved === '1' ? 'Resolved' : 'Active';
					html +=
						'<td><span class="ipcd-status-badge ipcd-status-' +
						statusClass +
						'">' +
						statusLabel +
						'</span></td>';
				}

				html += '<td>';
				if (conflict.resolved !== '1') {
					html +=
						'<button type="button" class="button button-small ipcd-btn-resolve" data-conflict-id="' +
						IPCD.escapeHtml(String(conflict.id)) +
						'">Resolve</button>';
				}
				html += '</td>';
				html += '</tr>';
			});

			$body.html(html);
		},

		/**
		 * Render scan history table.
		 *
		 * @param {Array} data Scans data.
		 */
		renderScansTable: function (data) {
			var $body = $('#ipcd-scans-body');

			if (!data || data.length === 0) {
				$body.html(
					'<tr><td colspan="8" class="ipcd-no-data">No scans have been run yet.</td></tr>'
				);
				return;
			}

			var html = '';

			data.forEach(function (scan) {
				html += '<tr>';
				html += '<td>#' + IPCD.escapeHtml(String(scan.id)) + '</td>';
				html +=
					'<td>' +
					IPCD.formatScanType(scan.scan_type) +
					'</td>';
				html +=
					'<td><span class="ipcd-status-badge ipcd-status-' +
					IPCD.escapeHtml(scan.status) +
					'">' +
					IPCD.escapeHtml(scan.status) +
					'</span></td>';
				html +=
					'<td>' +
					IPCD.escapeHtml(String(scan.total_plugins)) +
					'</td>';
				html +=
					'<td>' +
					IPCD.escapeHtml(String(scan.tested_combinations)) +
					'</td>';
				html +=
					'<td><strong>' +
					IPCD.escapeHtml(String(scan.conflicts_found)) +
					'</strong></td>';
				html +=
					'<td>' +
					IPCD.escapeHtml(scan.started_at || '—') +
					'</td>';
				html +=
					'<td>' +
					IPCD.escapeHtml(scan.completed_at || '—') +
					'</td>';
				html += '</tr>';
			});

			$body.html(html);
		},

		/**
		 * Format conflict type for display.
		 *
		 * @param {string} type Conflict type.
		 * @return {string} Formatted type.
		 */
		formatConflictType: function (type) {
			var types = {
				function_collision: 'Function Collision',
				class_collision: 'Class Collision',
				hook_conflict: 'Hook Conflict',
				resource_conflict: 'Resource Conflict',
				dependency_conflict: 'Dependency Conflict',
				runtime_error: 'Runtime Error',
				error: 'Error',
			};

			return IPCD.escapeHtml(types[type] || type);
		},

		/**
		 * Format scan type for display.
		 *
		 * @param {string} type Scan type.
		 * @return {string} Formatted type.
		 */
		formatScanType: function (type) {
			var types = {
				full: 'Full Scan',
				manual_full: 'Manual Full Scan',
				manual_quick: 'Quick Health Check',
				manual_isolation: 'Isolation Test',
				scheduled_full: 'Scheduled Scan',
				plugin_activation: 'Plugin Activation',
			};

			return IPCD.escapeHtml(types[type] || type);
		},

		/**
		 * Escape HTML entities to prevent XSS.
		 *
		 * @param {string} str Input string.
		 * @return {string} Escaped string.
		 */
		escapeHtml: function (str) {
			if (!str) {
				return '';
			}
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(str));
			return div.innerHTML;
		},
	};

	// Initialize when DOM is ready.
	$(document).ready(function () {
		IPCD.init();
	});
})(jQuery);
