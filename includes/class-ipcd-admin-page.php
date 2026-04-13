<?php
/**
 * Admin page for the conflict detector dashboard.
 *
 * @package IntelligentPluginConflictDetector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IPCD_Admin_Page
 *
 * Registers the admin menu page and enqueues assets.
 */
class IPCD_Admin_Page {

	/**
	 * Database handler.
	 *
	 * @var IPCD_Database
	 */
	private $database;

	/**
	 * Constructor.
	 *
	 * @param IPCD_Database $database Database handler.
	 */
	public function __construct( IPCD_Database $database ) {
		$this->database = $database;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'display_critical_notice' ) );

		// Add plugin action links.
		add_filter( 'plugin_action_links_' . IPCD_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
	}

	/**
	 * Register the admin menu.
	 */
	public function register_menu() {
		// Main menu page.
		add_menu_page(
			__( 'Plugin Conflicts', 'intelligent-plugin-conflict-detector' ),
			__( 'Plugin Conflicts', 'intelligent-plugin-conflict-detector' ),
			'manage_options',
			'ipcd-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-warning',
			80
		);

		// Dashboard submenu.
		add_submenu_page(
			'ipcd-dashboard',
			__( 'Dashboard', 'intelligent-plugin-conflict-detector' ),
			__( 'Dashboard', 'intelligent-plugin-conflict-detector' ),
			'manage_options',
			'ipcd-dashboard',
			array( $this, 'render_dashboard' )
		);

		// Conflicts list submenu.
		add_submenu_page(
			'ipcd-dashboard',
			__( 'Conflicts', 'intelligent-plugin-conflict-detector' ),
			__( 'Conflicts', 'intelligent-plugin-conflict-detector' ),
			'manage_options',
			'ipcd-conflicts',
			array( $this, 'render_conflicts' )
		);

		// Scan history submenu.
		add_submenu_page(
			'ipcd-dashboard',
			__( 'Scan History', 'intelligent-plugin-conflict-detector' ),
			__( 'Scan History', 'intelligent-plugin-conflict-detector' ),
			'manage_options',
			'ipcd-scans',
			array( $this, 'render_scans' )
		);

		// Settings submenu.
		add_submenu_page(
			'ipcd-dashboard',
			__( 'Settings', 'intelligent-plugin-conflict-detector' ),
			__( 'Settings', 'intelligent-plugin-conflict-detector' ),
			'manage_options',
			'ipcd-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		// Only load on our plugin pages.
		$plugin_pages = array(
			'toplevel_page_ipcd-dashboard',
			'plugin-conflicts_page_ipcd-conflicts',
			'plugin-conflicts_page_ipcd-scans',
			'plugin-conflicts_page_ipcd-settings',
		);

		if ( ! in_array( $hook_suffix, $plugin_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'ipcd-admin',
			IPCD_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			IPCD_VERSION
		);

		wp_enqueue_script(
			'ipcd-admin',
			IPCD_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-api-fetch' ),
			IPCD_VERSION,
			true
		);

		wp_localize_script(
			'ipcd-admin',
			'ipcdAdmin',
			array(
				'restUrl'   => rest_url( IPCD_Rest_API::NAMESPACE ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'adminUrl'  => admin_url(),
				'strings'   => array(
					'scanning'      => __( 'Scanning...', 'intelligent-plugin-conflict-detector' ),
					'scanComplete'  => __( 'Scan complete!', 'intelligent-plugin-conflict-detector' ),
					'scanFailed'    => __( 'Scan failed. Please try again.', 'intelligent-plugin-conflict-detector' ),
					'resolving'     => __( 'Resolving...', 'intelligent-plugin-conflict-detector' ),
					'resolved'      => __( 'Resolved', 'intelligent-plugin-conflict-detector' ),
					'confirmScan'   => __( 'This will run a full conflict scan. It may take several minutes. Continue?', 'intelligent-plugin-conflict-detector' ),
					'savingSettings' => __( 'Saving...', 'intelligent-plugin-conflict-detector' ),
					'settingsSaved' => __( 'Settings saved!', 'intelligent-plugin-conflict-detector' ),
				),
			)
		);
	}

	/**
	 * Display a critical admin notice if there are unresolved critical conflicts.
	 */
	public function display_critical_notice() {
		$stats = $this->database->get_conflict_stats();

		if ( $stats['critical'] > 0 ) {
			$url = admin_url( 'admin.php?page=ipcd-conflicts&severity=critical' );

			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'Plugin Conflict Detector:', 'intelligent-plugin-conflict-detector' ),
				sprintf(
					/* translators: %d: number of critical conflicts */
					esc_html__( '%d critical plugin conflict(s) detected!', 'intelligent-plugin-conflict-detector' ),
					$stats['critical']
				),
				esc_url( $url ),
				esc_html__( 'View details →', 'intelligent-plugin-conflict-detector' )
			);
		}
	}

	/**
	 * Add action links to the plugins page.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=ipcd-dashboard' ) ) . '">' .
				esc_html__( 'Dashboard', 'intelligent-plugin-conflict-detector' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=ipcd-settings' ) ) . '">' .
				esc_html__( 'Settings', 'intelligent-plugin-conflict-detector' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Render the main dashboard page.
	 */
	public function render_dashboard() {
		include IPCD_PLUGIN_DIR . 'templates/admin-page.php';
	}

	/**
	 * Render the conflicts list page.
	 */
	public function render_conflicts() {
		include IPCD_PLUGIN_DIR . 'templates/admin-page.php';
	}

	/**
	 * Render the scan history page.
	 */
	public function render_scans() {
		include IPCD_PLUGIN_DIR . 'templates/admin-page.php';
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings() {
		include IPCD_PLUGIN_DIR . 'templates/admin-page.php';
	}
}
