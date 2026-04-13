<?php
/**
 * Unit tests for IPCD_Rollback_Manager.
 *
 * @package IPCD\Tests\Unit
 */

namespace IPCD\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use IPCD_Plugin_State_Manager;
use IPCD_Rollback_Manager;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Class RollbackManagerTest
 */
class RollbackManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// rollback – success path
	// -------------------------------------------------------------------------

	public function test_rollback_returns_success_when_snapshot_restored(): void {
		$state_mock = Mockery::mock( IPCD_Plugin_State_Manager::class );

		$state_mock->shouldReceive( 'capture_snapshot' )
			->once()
			->andReturn( 'pre_snapshot_123' );

		$state_mock->shouldReceive( 'restore_snapshot' )
			->once()
			->with( 'target_snapshot' )
			->andReturn( true );

		// update_option is called to store rollback history.
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );

		$rollback = new IPCD_Rollback_Manager( $state_mock );
		$result   = $rollback->rollback( 'target_snapshot' );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'pre_rollback_snapshot', $result );
	}

	// -------------------------------------------------------------------------
	// rollback – failure path
	// -------------------------------------------------------------------------

	public function test_rollback_returns_failure_when_snapshot_not_found(): void {
		$state_mock = Mockery::mock( IPCD_Plugin_State_Manager::class );

		$state_mock->shouldReceive( 'capture_snapshot' )
			->once()
			->andReturn( 'pre_snapshot_456' );

		$state_mock->shouldReceive( 'restore_snapshot' )
			->once()
			->andReturn( false );

		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );

		$rollback = new IPCD_Rollback_Manager( $state_mock );
		$result   = $rollback->rollback( 'missing_snapshot' );

		$this->assertFalse( $result['success'] );
	}

	// -------------------------------------------------------------------------
	// get_history
	// -------------------------------------------------------------------------

	public function test_get_history_returns_array(): void {
		$state_mock = Mockery::mock( IPCD_Plugin_State_Manager::class );
		$rollback   = new IPCD_Rollback_Manager( $state_mock );

		$history = $rollback->get_history();
		$this->assertIsArray( $history );
	}

	// -------------------------------------------------------------------------
	// clear_history
	// -------------------------------------------------------------------------

	public function test_clear_history_deletes_option(): void {
		$state_mock  = Mockery::mock( IPCD_Plugin_State_Manager::class );
		$deleted_key = null;

		Functions\when( 'delete_option' )
			->alias( static function ( $key ) use ( &$deleted_key ) {
				$deleted_key = $key;
				return true;
			} );

		$rollback = new IPCD_Rollback_Manager( $state_mock );
		$rollback->clear_history();

		$this->assertSame( IPCD_Rollback_Manager::HISTORY_OPTION, $deleted_key );
	}
}
