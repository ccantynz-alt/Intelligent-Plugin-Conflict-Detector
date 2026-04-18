<?php
/**
 * Admin Class
 *
 * Registers admin menus, AJAX handlers and settings page for the IPCD plugin.
 *
 * @package IPCD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IPCD_Admin
 */
class IPCD_Admin {

	/** @var IPCD_Conflict_Detector */
	private $detector;

	/** @var IPCD_Rollback_Manager */
	private $rollback;

	/** @var IPCD_Notification_Manager */
	private $notifications;

	/** @var IPCD_Background_Tester */
	private $tester;

	/** @var IPCD_Plugin_State_Manager */
	private $state_manager;

	/**
	 * Constructor – wire admin hooks.
	 *
	 * @param IPCD_Conflict_Detector    $detector       Conflict detector.
	 * @param IPCD_Rollback_Manager     $rollback       Rollback manager.
	 * @param IPCD_Notification_Manager $notifications  Notification manager.
	 * @param IPCD_Background_Tester    $tester         Background tester.
	 * @param IPCD_Plugin_State_Manager $state_manager  State manager.
	 */
	public function __construct(
		IPCD_Conflict_Detector $detector,
		IPCD_Rollback_Manager $rollback,
		IPCD_Notification_Manager $notifications,
		IPCD_Background_Tester $tester,
		IPCD_Plugin_State_Manager $state_manager
	) {
		$this->detector      = $detector;
		$this->rollback      = $rollback;
		$this->notifications = $notifications;
		$this->tester        = $tester;
		$this->state_manager = $state_manager;

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_ipcd_rollback', array( $this, 'ajax_rollback' ) );
		add_action( 'wp_ajax_ipcd_resolve_conflict', array( $this, 'ajax_resolve_conflict' ) );
		add_action( 'wp_ajax_ipcd_run_test', array( $this, 'ajax_run_test' ) );
		add_action( 'wp_ajax_ipcd_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_ipcd_create_snapshot', array( $this, 'ajax_create_snapshot' ) );
		add_action( 'wp_ajax_ipcd_delete_snapshot', array( $this, 'ajax_delete_snapshot' ) );
		add_action( 'wp_ajax_ipcd_clear_conflicts', array( $this, 'ajax_clear_all_conflicts' ) );

		// Lightweight health-ping endpoint (no auth required – used by the background tester).
		add_action( 'wp_ajax_nopriv_ipcd_health_ping', array( $this, 'health_ping' ) );
		add_action( 'wp_ajax_ipcd_health_ping', array( $this, 'health_ping' ) );

		// Admin columns on Plugins page.
		add_filter( 'plugin_action_links', array( $this, 'add_plugin_action_links' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Menu & Assets
	// -------------------------------------------------------------------------

	/**
	 * Register the admin menu pages.
	 */
	public function register_admin_menu(): void {
		add_management_page(
			__( 'Plugin Conflict Detector', 'ipcd' ),
			__( 'Conflict Detector', 'ipcd' ),
			'manage_options',
			'ipcd-dashboard',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			null, // Hidden submenu (accessed via tab on dashboard page).
			__( 'IPCD Settings', 'ipcd' ),
			__( 'Settings', 'ipcd' ),
			'manage_options',
			'ipcd-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Enqueue admin CSS and JS on IPCD pages.
	 *
	 * @param string $hook  Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		$ipcd_pages = array( 'tools_page_ipcd-dashboard', 'admin_page_ipcd-settings' );
		if ( ! in_array( $hook, $ipcd_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'ipcd-admin',
			IPCD_PLUGIN_URL . 'admin/assets/css/admin.css',
			array(),
			IPCD_VERSION
		);

		wp_enqueue_script(
			'ipcd-admin',
			IPCD_PLUGIN_URL . 'admin/assets/js/admin.js',
			array( 'jquery' ),
			IPCD_VERSION,
			true
		);

		wp_localize_script(
			'ipcd-admin',
			'ipcdData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ipcd_nonce' ),
				'strings' => array(
					'confirmRollback'  => __( 'Are you sure you want to rollback to this snapshot? Your current plugin state will be saved first.', 'ipcd' ),
					'confirmClear'     => __( 'Are you sure you want to clear all conflict records?', 'ipcd' ),
					'rollingBack'      => __( 'Rolling back…', 'ipcd' ),
					'runningTest'      => __( 'Running test…', 'ipcd' ),
					'saving'           => __( 'Saving…', 'ipcd' ),
					'done'             => __( 'Done!', 'ipcd' ),
					'error'            => __( 'An error occurred. Please try again.', 'ipcd' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// View renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ipcd' ) );
		}
		include IPCD_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ipcd' ) );
		}
		include IPCD_PLUGIN_DIR . 'admin/views/settings.php';
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Perform a rollback.
	 */
	public function ajax_rollback(): void {
		$this->verify_ajax_nonce();

		$snapshot_id = sanitize_text_field( $_POST['snapshot_id'] ?? '' );
		if ( ! $snapshot_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing snapshot ID.', 'ipcd' ) ) );
		}

		$result = $this->rollback->rollback( $snapshot_id );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: Resolve a conflict.
	 */
	public function ajax_resolve_conflict(): void {
		$this->verify_ajax_nonce();

		$conflict_id = sanitize_text_field( $_POST['conflict_id'] ?? '' );
		if ( ! $conflict_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing conflict ID.', 'ipcd' ) ) );
		}

		$resolved = $this->detector->resolve_conflict( $conflict_id );
		if ( $resolved ) {
			wp_send_json_success( array( 'message' => __( 'Conflict marked as resolved.', 'ipcd' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Conflict not found.', 'ipcd' ) ) );
		}
	}

	/**
	 * AJAX: Manually trigger a background test run.
	 */
	public function ajax_run_test(): void {
		$this->verify_ajax_nonce();

		$plugin = sanitize_text_field( $_POST['plugin'] ?? '' );
		$event  = sanitize_key( $_POST['event'] ?? 'manual' );

		if ( ! $plugin ) {
			// Run a full-site health probe with no specific plugin attributed.
			$plugin = 'manual-check';
		}

		$this->tester->run_single_test( $plugin, $event );

		wp_send_json_success( array( 'message' => __( 'Test completed. Refresh the page to see results.', 'ipcd' ) ) );
	}

	/**
	 * AJAX: Save settings.
	 */
	public function ajax_save_settings(): void {
		$this->verify_ajax_nonce();

		$this->notifications->save_settings( $_POST );
		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'ipcd' ) ) );
	}

	/**
	 * AJAX: Create a manual snapshot.
	 */
	public function ajax_create_snapshot(): void {
		$this->verify_ajax_nonce();

		$label       = sanitize_text_field( $_POST['label'] ?? 'manual_snapshot' );
		$snapshot_id = $this->state_manager->capture_snapshot( $label );
		wp_send_json_success(
			array(
				'snapshot_id' => $snapshot_id,
				'message'     => __( 'Snapshot created successfully.', 'ipcd' ),
			)
		);
	}

	/**
	 * AJAX: Delete a snapshot.
	 */
	public function ajax_delete_snapshot(): void {
		$this->verify_ajax_nonce();

		$snapshot_id = sanitize_text_field( $_POST['snapshot_id'] ?? '' );
		if ( ! $snapshot_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing snapshot ID.', 'ipcd' ) ) );
		}

		$deleted = $this->state_manager->delete_snapshot( $snapshot_id );
		if ( $deleted ) {
			wp_send_json_success( array( 'message' => __( 'Snapshot deleted.', 'ipcd' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Snapshot not found.', 'ipcd' ) ) );
		}
	}

	/**
	 * AJAX: Clear all conflict records.
	 */
	public function ajax_clear_all_conflicts(): void {
		$this->verify_ajax_nonce();

		$this->detector->clear_all_conflicts();
		wp_send_json_success( array( 'message' => __( 'All conflicts cleared.', 'ipcd' ) ) );
	}

	/**
	 * Health ping endpoint – returns 200 OK with a JSON body.
	 */
	public function health_ping(): void {
		wp_send_json_success( array( 'status' => 'ok' ) );
	}

	// -------------------------------------------------------------------------
	// Plugins list enhancement
	// -------------------------------------------------------------------------

	/**
	 * Add "View Conflicts" link to plugin action links if conflicts exist.
	 *
	 * @param array  $actions        Existing action links.
	 * @param string $plugin_file    Plugin basename.
	 *
	 * @return array
	 */
	public function add_plugin_action_links( array $actions, string $plugin_file ): array {
		$conflicts = array_filter(
			$this->detector->get_active_conflicts(),
			static function ( $c ) use ( $plugin_file ) {
				return $c['plugin'] === $plugin_file;
			}
		);

		if ( ! empty( $conflicts ) ) {
			$count           = count( $conflicts );
			$url             = admin_url( 'tools.php?page=ipcd-dashboard' );
			$actions['ipcd'] = sprintf(
				'<a href="%s" style="color:#d63638;font-weight:600;">⚠ %s</a>',
				esc_url( $url ),
				sprintf(
					/* translators: %d: number of conflicts */
					_n( '%d Conflict', '%d Conflicts', $count, 'ipcd' ),
					$count
				)
			);
		}

		return $actions;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Verify the AJAX nonce and capability, die on failure.
	 */
	private function verify_ajax_nonce(): void {
		if (
			! check_ajax_referer( 'ipcd_nonce', 'nonce', false ) ||
			! current_user_can( 'manage_options' )
		) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ipcd' ) ), 403 );
			wp_die();
		}
	}
}
