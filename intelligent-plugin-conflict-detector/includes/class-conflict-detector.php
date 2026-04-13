<?php
/**
 * Conflict Detector
 *
 * Records, retrieves and clears plugin conflicts. A "conflict" is anything
 * that causes an observable failure after a plugin change: a PHP fatal, a
 * JavaScript console error on admin pages, or an HTTP 500 response from the
 * site front-end.
 *
 * @package IPCD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IPCD_Conflict_Detector
 */
class IPCD_Conflict_Detector {

	/** Option key used to store the active conflict log. */
	const OPTION_KEY = 'ipcd_conflicts';

	/** Maximum number of conflict records to keep. */
	const MAX_RECORDS = 200;

	/**
	 * Severity levels.
	 */
	const SEVERITY_CRITICAL = 'critical';
	const SEVERITY_WARNING  = 'warning';
	const SEVERITY_INFO     = 'info';

	/**
	 * Return all stored conflicts, newest first.
	 *
	 * @return array
	 */
	public function get_conflicts(): array {
		$conflicts = get_option( self::OPTION_KEY, array() );
		return is_array( $conflicts ) ? $conflicts : array();
	}

	/**
	 * Return only unresolved (active) conflicts.
	 *
	 * @return array
	 */
	public function get_active_conflicts(): array {
		return array_filter(
			$this->get_conflicts(),
			static function ( $conflict ) {
				return empty( $conflict['resolved'] );
			}
		);
	}

	/**
	 * Record a new conflict.
	 *
	 * @param string $plugin_basename  The plugin that caused / is involved in the conflict.
	 * @param string $type             Type of conflict (e.g. 'php_fatal', 'js_error', 'http_error').
	 * @param string $message          Human-readable description.
	 * @param string $severity         One of the SEVERITY_* constants.
	 * @param array  $context          Optional extra data (stack trace, URL, etc.).
	 *
	 * @return string  Conflict ID.
	 */
	public function record_conflict(
		string $plugin_basename,
		string $type,
		string $message,
		string $severity = self::SEVERITY_WARNING,
		array $context = array()
	): string {
		$conflicts   = $this->get_conflicts();
		$conflict_id = uniqid( 'ipcd_conflict_', true );

		array_unshift(
			$conflicts,
			array(
				'id'       => $conflict_id,
				'plugin'   => sanitize_text_field( $plugin_basename ),
				'type'     => sanitize_key( $type ),
				'message'  => sanitize_text_field( $message ),
				'severity' => $this->sanitize_severity( $severity ),
				'context'  => $context,
				'resolved' => false,
				'time'     => time(),
			)
		);

		// Trim to max records.
		if ( count( $conflicts ) > self::MAX_RECORDS ) {
			$conflicts = array_slice( $conflicts, 0, self::MAX_RECORDS );
		}

		update_option( self::OPTION_KEY, $conflicts );

		/**
		 * Fired after a conflict has been recorded.
		 *
		 * @param array  $conflict    The full conflict record.
		 * @param string $conflict_id The generated conflict ID.
		 */
		do_action( 'ipcd_conflict_recorded', $conflicts[0], $conflict_id );

		return $conflict_id;
	}

	/**
	 * Mark a conflict as resolved.
	 *
	 * @param string $conflict_id  Conflict ID to resolve.
	 *
	 * @return bool
	 */
	public function resolve_conflict( string $conflict_id ): bool {
		$conflicts = $this->get_conflicts();
		$updated   = false;

		foreach ( $conflicts as &$conflict ) {
			if ( isset( $conflict['id'] ) && $conflict['id'] === $conflict_id ) {
				$conflict['resolved']     = true;
				$conflict['resolved_at']  = time();
				$updated                  = true;
				break;
			}
		}
		unset( $conflict );

		if ( $updated ) {
			update_option( self::OPTION_KEY, $conflicts );
		}

		return $updated;
	}

	/**
	 * Remove all stored conflicts for a specific plugin.
	 *
	 * @param string $plugin_basename  Plugin basename.
	 */
	public function clear_conflicts_for_plugin( string $plugin_basename ): void {
		$conflicts = $this->get_conflicts();
		$filtered  = array_filter(
			$conflicts,
			static function ( $conflict ) use ( $plugin_basename ) {
				return $conflict['plugin'] !== $plugin_basename;
			}
		);

		update_option( self::OPTION_KEY, array_values( $filtered ) );
	}

	/**
	 * Delete all stored conflicts.
	 */
	public function clear_all_conflicts(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Analyse the PHP error log for fatal errors introduced by a plugin change.
	 *
	 * @param string $plugin_basename  Plugin to attribute errors to.
	 * @param int    $since_timestamp  Only look at log entries after this timestamp.
	 *
	 * @return array  Array of new conflict IDs.
	 */
	public function scan_error_log( string $plugin_basename, int $since_timestamp = 0 ): array {
		$conflict_ids = array();

		$log_file = ini_get( 'error_log' );
		if ( ! $log_file || ! file_exists( $log_file ) || ! is_readable( $log_file ) ) {
			return $conflict_ids;
		}

		$lines  = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			return $conflict_ids;
		}

		// Only consider lines after the given timestamp.
		foreach ( array_reverse( $lines ) as $line ) {
			// Log lines typically start with "[13-Apr-2026 12:00:00 UTC]"
			if ( preg_match( '/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2} [A-Z]+)\]/', $line, $m ) ) {
				$line_time = strtotime( $m[1] );
				if ( $line_time && $line_time < $since_timestamp ) {
					break;
				}
			}

			// Detect PHP fatal / parse errors.
			if ( preg_match( '/PHP (Fatal error|Parse error|Error):(.*)/i', $line, $matches ) ) {
				$conflict_ids[] = $this->record_conflict(
					$plugin_basename,
					'php_fatal',
					trim( $matches[2] ),
					self::SEVERITY_CRITICAL,
					array( 'raw_log_line' => $line )
				);
			}
		}

		return $conflict_ids;
	}

	/**
	 * Probe a URL and report HTTP errors.
	 *
	 * @param string $url              URL to probe.
	 * @param string $plugin_basename  Plugin to attribute the error to.
	 *
	 * @return string|null  Conflict ID if an error was detected, null otherwise.
	 */
	public function probe_url( string $url, string $plugin_basename ): ?string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'IPCD-Conflict-Probe/1.0',
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->record_conflict(
				$plugin_basename,
				'http_error',
				$response->get_error_message(),
				self::SEVERITY_CRITICAL,
				array( 'url' => $url )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 500 ) {
			return $this->record_conflict(
				$plugin_basename,
				'http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: URL */
					__( 'HTTP %1$d response detected at %2$s', 'ipcd' ),
					$status_code,
					$url
				),
				self::SEVERITY_CRITICAL,
				array(
					'url'         => $url,
					'status_code' => $status_code,
				)
			);
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Ensure severity is one of the defined constants.
	 *
	 * @param string $severity  Raw severity string.
	 *
	 * @return string
	 */
	private function sanitize_severity( string $severity ): string {
		$allowed = array( self::SEVERITY_CRITICAL, self::SEVERITY_WARNING, self::SEVERITY_INFO );
		return in_array( $severity, $allowed, true ) ? $severity : self::SEVERITY_WARNING;
	}
}
