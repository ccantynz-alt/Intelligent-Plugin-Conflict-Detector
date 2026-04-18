<?php
/**
 * Unit tests for IPCD_Conflict_Detector.
 *
 * @package IPCD\Tests\Unit
 */

namespace IPCD\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use IPCD_Conflict_Detector;
use PHPUnit\Framework\TestCase;

/**
 * Class ConflictDetectorTest
 */
class ConflictDetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the WordPress functions that every test needs.
	 * Called at the start of each test so we can also add test-specific stubs
	 * before or after without conflicts.
	 */
	private function stub_wp_functions(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
	}

	// -------------------------------------------------------------------------
	// record_conflict
	// -------------------------------------------------------------------------

	public function test_record_conflict_returns_string_id(): void {
		$this->stub_wp_functions();

		$detector = new IPCD_Conflict_Detector();
		$id       = $detector->record_conflict(
			'some-plugin/plugin.php',
			'php_fatal',
			'Call to undefined function foo()',
			IPCD_Conflict_Detector::SEVERITY_CRITICAL
		);

		$this->assertIsString( $id );
		$this->assertStringStartsWith( 'ipcd_conflict_', $id );
	}

	public function test_record_conflict_falls_back_to_warning_for_invalid_severity(): void {
		$recorded = array();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'update_option' )
			->alias( static function ( $key, $value ) use ( &$recorded ) {
				$recorded = $value;
				return true;
			} );

		$detector = new IPCD_Conflict_Detector();
		$detector->record_conflict(
			'bad-plugin/bad.php',
			'http_error',
			'HTTP 500',
			'nonsense_severity'
		);

		$this->assertNotEmpty( $recorded );
		$this->assertSame( 'warning', $recorded[0]['severity'] );
	}

	// -------------------------------------------------------------------------
	// get_conflicts / get_active_conflicts
	// -------------------------------------------------------------------------

	public function test_get_conflicts_returns_array_when_option_is_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$detector  = new IPCD_Conflict_Detector();
		$conflicts = $detector->get_conflicts();
		$this->assertIsArray( $conflicts );
		$this->assertCount( 0, $conflicts );
	}

	public function test_get_active_conflicts_excludes_resolved(): void {
		$stored = array(
			array( 'id' => 'c1', 'plugin' => 'foo/foo.php', 'type' => 'php_fatal', 'message' => 'Error', 'severity' => 'critical', 'context' => array(), 'resolved' => false, 'time' => time() ),
			array( 'id' => 'c2', 'plugin' => 'bar/bar.php', 'type' => 'http_error', 'message' => 'HTTP 500', 'severity' => 'critical', 'context' => array(), 'resolved' => true, 'time' => time() ),
		);

		Functions\when( 'get_option' )->justReturn( $stored );

		$detector = new IPCD_Conflict_Detector();
		$active   = $detector->get_active_conflicts();

		$this->assertCount( 1, $active );
		$this->assertSame( 'c1', array_values( $active )[0]['id'] );
	}

	// -------------------------------------------------------------------------
	// resolve_conflict
	// -------------------------------------------------------------------------

	public function test_resolve_conflict_returns_true_for_known_id(): void {
		$stored = array(
			array( 'id' => 'abc123', 'plugin' => 'foo/foo.php', 'type' => 'php_fatal', 'message' => 'err', 'severity' => 'critical', 'context' => array(), 'resolved' => false, 'time' => time() ),
		);

		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'update_option' )->justReturn( true );

		$detector = new IPCD_Conflict_Detector();
		$result   = $detector->resolve_conflict( 'abc123' );

		$this->assertTrue( $result );
	}

	public function test_resolve_conflict_returns_false_for_unknown_id(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$detector = new IPCD_Conflict_Detector();
		$result   = $detector->resolve_conflict( 'nonexistent_id' );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// clear_conflicts_for_plugin
	// -------------------------------------------------------------------------

	public function test_clear_conflicts_for_plugin_removes_matching_entries(): void {
		$stored = array(
			array( 'id' => '1', 'plugin' => 'target/target.php', 'type' => 't', 'message' => 'm', 'severity' => 'info', 'context' => array(), 'resolved' => false, 'time' => time() ),
			array( 'id' => '2', 'plugin' => 'other/other.php',   'type' => 't', 'message' => 'm', 'severity' => 'info', 'context' => array(), 'resolved' => false, 'time' => time() ),
		);

		$saved = null;
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'update_option' )
			->alias( static function ( $key, $value ) use ( &$saved ) {
				$saved = $value;
				return true;
			} );

		$detector = new IPCD_Conflict_Detector();
		$detector->clear_conflicts_for_plugin( 'target/target.php' );

		$this->assertIsArray( $saved );
		$this->assertCount( 1, $saved );
		$this->assertSame( 'other/other.php', $saved[0]['plugin'] );
	}

	// -------------------------------------------------------------------------
	// clear_all_conflicts
	// -------------------------------------------------------------------------

	public function test_clear_all_conflicts_clears_conflicts(): void {
		$deleted_key = null;
		Functions\when( 'delete_option' )
			->alias( static function ( $key ) use ( &$deleted_key ) {
				$deleted_key = $key;
				return true;
			} );

		$detector = new IPCD_Conflict_Detector();
		$detector->clear_all_conflicts();

		$this->assertSame( IPCD_Conflict_Detector::OPTION_KEY, $deleted_key );
	}
}
