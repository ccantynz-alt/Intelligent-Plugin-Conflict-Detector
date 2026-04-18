<?php
/**
 * Unit tests for IPCD_Background_Tester.
 *
 * @package IPCD\Tests\Unit
 */

namespace IPCD\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use IPCD_Background_Tester;
use IPCD_Conflict_Detector;
use IPCD_Plugin_State_Manager;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Class BackgroundTesterTest
 */
class BackgroundTesterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'add_filter' )->justReturn( null );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// schedule_test
	// -------------------------------------------------------------------------

	public function test_schedule_test_adds_to_queue(): void {
		$detector_mock      = Mockery::mock( IPCD_Conflict_Detector::class );
		$state_manager_mock = Mockery::mock( IPCD_Plugin_State_Manager::class );

		$saved_queue = null;
		Functions\when( 'update_option' )
			->alias( static function ( $key, $value ) use ( &$saved_queue ) {
				if ( IPCD_Background_Tester::QUEUE_OPTION === $key ) {
					$saved_queue = $value;
				}
				return true;
			} );

		$tester = new IPCD_Background_Tester( $detector_mock, $state_manager_mock );
		$tester->schedule_test( 'test-plugin/test-plugin.php', 'activated' );

		$this->assertIsArray( $saved_queue );
		$this->assertCount( 1, $saved_queue );
		$this->assertSame( 'test-plugin/test-plugin.php', $saved_queue[0]['plugin'] );
		$this->assertSame( 'activated', $saved_queue[0]['event'] );
	}

	// -------------------------------------------------------------------------
	// run_queued_tests – empty queue
	// -------------------------------------------------------------------------

	public function test_run_queued_tests_does_nothing_when_queue_is_empty(): void {
		$detector_mock      = Mockery::mock( IPCD_Conflict_Detector::class );
		$state_manager_mock = Mockery::mock( IPCD_Plugin_State_Manager::class );

		$tester = new IPCD_Background_Tester( $detector_mock, $state_manager_mock );

		// Should not throw – and no AJAX calls / conflict detections happen.
		$tester->run_queued_tests();
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// get_queue
	// -------------------------------------------------------------------------

	public function test_get_queue_returns_array(): void {
		$detector_mock      = Mockery::mock( IPCD_Conflict_Detector::class );
		$state_manager_mock = Mockery::mock( IPCD_Plugin_State_Manager::class );

		$tester = new IPCD_Background_Tester( $detector_mock, $state_manager_mock );
		$queue  = $tester->get_queue();

		$this->assertIsArray( $queue );
	}

	// -------------------------------------------------------------------------
	// get_next_run
	// -------------------------------------------------------------------------

	public function test_get_next_run_returns_null_when_not_scheduled(): void {
		// wp_next_scheduled already stubbed to return false in setUp.
		$detector_mock      = Mockery::mock( IPCD_Conflict_Detector::class );
		$state_manager_mock = Mockery::mock( IPCD_Plugin_State_Manager::class );

		$tester = new IPCD_Background_Tester( $detector_mock, $state_manager_mock );
		$next   = $tester->get_next_run();

		$this->assertNull( $next );
	}

	public function test_get_next_run_returns_timestamp_when_scheduled(): void {
		// Override the setUp stub for this test.
		Functions\when( 'wp_next_scheduled' )->justReturn( 1_700_000_000 );

		$detector_mock      = Mockery::mock( IPCD_Conflict_Detector::class );
		$state_manager_mock = Mockery::mock( IPCD_Plugin_State_Manager::class );

		$tester = new IPCD_Background_Tester( $detector_mock, $state_manager_mock );
		$next   = $tester->get_next_run();

		$this->assertSame( 1_700_000_000, $next );
	}
}
