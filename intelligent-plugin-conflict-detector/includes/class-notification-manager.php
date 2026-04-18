<?php
/**
 * Notification Manager
 *
 * Handles alerting the site admin when conflicts are detected.  Supports
 * both WordPress admin-notice banners and optional email alerts.
 *
 * @package IPCD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IPCD_Notification_Manager
 */
class IPCD_Notification_Manager {

	/** Option key for pending admin notices. */
	const NOTICES_OPTION = 'ipcd_pending_notices';

	/** Option key for plugin settings. */
	const SETTINGS_OPTION = 'ipcd_settings';

	/**
	 * Constructor – wire hooks.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
		add_action( 'ipcd_conflicts_detected', array( $this, 'on_conflicts_detected' ), 10, 3 );
	}

	/**
	 * Called when the background tester finds new conflicts.
	 *
	 * @param string $plugin_basename  Plugin that caused the conflict.
	 * @param string $event            'activated' | 'updated'.
	 * @param array  $conflict_ids     IDs of new conflicts.
	 */
	public function on_conflicts_detected(
		string $plugin_basename,
		string $event,
		array $conflict_ids
	): void {
		$plugin_name = $this->get_plugin_name( $plugin_basename );
		$count       = count( $conflict_ids );

		$message = sprintf(
			/* translators: 1: plugin name, 2: event type, 3: number of conflicts */
			_n(
				'<strong>IPCD:</strong> %1$d conflict detected after %3$s <em>%2$s</em>. <a href="%4$s">View details</a>.',
				'<strong>IPCD:</strong> %1$d conflicts detected after %3$s <em>%2$s</em>. <a href="%4$s">View details</a>.',
				$count,
				'ipcd'
			),
			$count,
			esc_html( $plugin_name ),
			esc_html( $event ),
			esc_url( admin_url( 'tools.php?page=ipcd-dashboard' ) )
		);

		$this->queue_notice( $message, 'error' );

		// Send email notification if enabled.
		$settings = $this->get_settings();
		if ( ! empty( $settings['email_notifications'] ) ) {
			$this->send_email_alert( $plugin_name, $event, $count );
		}
	}

	/**
	 * Display queued admin notices.
	 */
	public function display_admin_notices(): void {
		$notices = $this->get_queued_notices();
		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			$type    = in_array( $notice['type'], array( 'error', 'warning', 'success', 'info' ), true )
				? $notice['type'] : 'info';
			$message = wp_kses(
				$notice['message'],
				array(
					'strong' => array(),
					'em'     => array(),
					'a'      => array( 'href' => array() ),
				)
			);
			printf(
				'<div class="notice notice-%s is-dismissible ipcd-notice"><p>%s</p></div>',
				esc_attr( $type ),
				$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already kses'd above
			);
		}

		// Clear notices after display.
		delete_option( self::NOTICES_OPTION );
	}

	/**
	 * Queue a notice to be displayed on the next admin page load.
	 *
	 * @param string $message  Notice message (may contain allowed HTML).
	 * @param string $type     'error' | 'warning' | 'success' | 'info'.
	 */
	public function queue_notice( string $message, string $type = 'info' ): void {
		$notices   = $this->get_queued_notices();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);
		update_option( self::NOTICES_OPTION, $notices );
	}

	/**
	 * Return queued notices.
	 *
	 * @return array
	 */
	public function get_queued_notices(): array {
		$notices = get_option( self::NOTICES_OPTION, array() );
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Return plugin settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		$defaults = array(
			'email_notifications' => false,
			'email_address'       => get_option( 'admin_email' ),
			'auto_rollback'       => false,
			'scan_interval_hours' => 6,
		);

		$saved = get_option( self::SETTINGS_OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Save plugin settings.
	 *
	 * @param array $settings  New settings values.
	 */
	public function save_settings( array $settings ): void {
		$sanitized = array(
			'email_notifications' => ! empty( $settings['email_notifications'] ),
			'email_address'       => sanitize_email( $settings['email_address'] ?? '' ),
			'auto_rollback'       => ! empty( $settings['auto_rollback'] ),
			'scan_interval_hours' => absint( $settings['scan_interval_hours'] ?? 6 ),
		);
		update_option( self::SETTINGS_OPTION, $sanitized );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Send an email alert about detected conflicts.
	 *
	 * @param string $plugin_name  Human-readable plugin name.
	 * @param string $event        'activated' | 'updated'.
	 * @param int    $count        Number of conflicts.
	 */
	private function send_email_alert( string $plugin_name, string $event, int $count ): void {
		$settings  = $this->get_settings();
		$recipient = $settings['email_address'] ?: get_option( 'admin_email' );
		$site_name = get_bloginfo( 'name' );

		$subject = sprintf(
			/* translators: 1: site name, 2: plugin name */
			__( '[%1$s] Plugin conflict detected — %2$s', 'ipcd' ),
			$site_name,
			$plugin_name
		);

		$body = sprintf(
			/* translators: 1: number of conflicts, 2: plugin name, 3: event, 4: dashboard URL */
			__(
				"Hello,\n\n%1\$d conflict(s) were detected on %5\$s after %2\$s was %3\$s.\n\nPlease visit your IPCD dashboard to review the conflicts and roll back if necessary:\n%4\$s\n\nThis message was sent automatically by the Intelligent Plugin Conflict Detector.",
				'ipcd'
			),
			$count,
			$plugin_name,
			$event,
			admin_url( 'tools.php?page=ipcd-dashboard' ),
			$site_name
		);

		wp_mail( $recipient, $subject, $body );
	}

	/**
	 * Get a human-readable plugin name from its basename.
	 *
	 * @param string $plugin_basename  Plugin basename (e.g. woocommerce/woocommerce.php).
	 *
	 * @return string
	 */
	private function get_plugin_name( string $plugin_basename ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = WP_PLUGIN_DIR . '/' . $plugin_basename;
		if ( file_exists( $plugin_file ) ) {
			$data = get_plugin_data( $plugin_file, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}

		return $plugin_basename;
	}
}
