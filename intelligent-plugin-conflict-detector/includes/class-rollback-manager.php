<?php
/**
 * Rollback Manager
 *
 * Provides one-click rollback to any previously captured plugin state snapshot.
 *
 * @package IPCD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IPCD_Rollback_Manager
 */
class IPCD_Rollback_Manager {

	/** Option key that stores rollback history. */
	const HISTORY_OPTION = 'ipcd_rollback_history';

	/** @var IPCD_Plugin_State_Manager */
	private $state_manager;

	/**
	 * Constructor.
	 *
	 * @param IPCD_Plugin_State_Manager $state_manager  State manager instance.
	 */
	public function __construct( IPCD_Plugin_State_Manager $state_manager ) {
		$this->state_manager = $state_manager;
	}

	/**
	 * Perform a rollback to the given snapshot.
	 *
	 * The current state is automatically snapshotted first so the rollback
	 * itself can be undone if needed.
	 *
	 * @param string $snapshot_id  ID of the snapshot to restore.
	 *
	 * @return array  Result array with 'success' (bool) and 'message' (string).
	 */
	public function rollback( string $snapshot_id ): array {
		// Safety snapshot of current state before rolling back.
		$pre_rollback_id = $this->state_manager->capture_snapshot(
			'pre_rollback_' . $snapshot_id
		);

		$success = $this->state_manager->restore_snapshot( $snapshot_id );

		if ( $success ) {
			$this->add_history_entry(
				$snapshot_id,
				$pre_rollback_id,
				'success'
			);

			/**
			 * Fires after a successful rollback.
			 *
			 * @param string $snapshot_id     The restored snapshot.
			 * @param string $pre_rollback_id The safety snapshot taken before restoring.
			 */
			do_action( 'ipcd_rollback_complete', $snapshot_id, $pre_rollback_id );

			return array(
				'success' => true,
				'message' => __( 'Rollback completed successfully. Active plugins have been restored.', 'ipcd' ),
				'pre_rollback_snapshot' => $pre_rollback_id,
			);
		}

		$this->add_history_entry( $snapshot_id, $pre_rollback_id, 'failed' );

		return array(
			'success' => false,
			'message' => __( 'Rollback failed: snapshot not found or could not be restored.', 'ipcd' ),
		);
	}

	/**
	 * Return rollback history, newest first.
	 *
	 * @return array
	 */
	public function get_history(): array {
		$history = get_option( self::HISTORY_OPTION, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Clear all rollback history entries.
	 */
	public function clear_history(): void {
		delete_option( self::HISTORY_OPTION );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Append an entry to the rollback history log.
	 *
	 * @param string $snapshot_id     The snapshot that was restored.
	 * @param string $pre_rollback_id The safety snapshot taken before rollback.
	 * @param string $status          'success' | 'failed'.
	 */
	private function add_history_entry(
		string $snapshot_id,
		string $pre_rollback_id,
		string $status
	): void {
		$history = $this->get_history();
		array_unshift(
			$history,
			array(
				'snapshot_id'     => $snapshot_id,
				'pre_rollback_id' => $pre_rollback_id,
				'status'          => $status,
				'time'            => time(),
			)
		);

		// Keep at most 50 history entries.
		if ( count( $history ) > 50 ) {
			$history = array_slice( $history, 0, 50 );
		}

		update_option( self::HISTORY_OPTION, $history );
	}
}
