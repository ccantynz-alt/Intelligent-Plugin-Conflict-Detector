<?php
/**
 * Unit tests for IPCD_Notification_Manager.
 *
 * @package IPCD\Tests\Unit
 */

namespace IPCD\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use IPCD_Notification_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Class NotificationManagerTest
 */
class NotificationManagerTest extends TestCase {

protected function setUp(): void {
parent::setUp();
Monkey\setUp();

Functions\when( 'add_action' )->justReturn( null );
Functions\when( '__' )->returnArg( 1 );
Functions\when( '_n' )->returnArg( 1 );
Functions\when( 'wp_parse_args' )->alias( static function ( $args, $defaults ) {
return array_merge( $defaults, (array) $args );
} );
Functions\when( 'sanitize_email' )->returnArg( 1 );
Functions\when( 'absint' )->alias( 'abs' );
Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
}

protected function tearDown(): void {
Monkey\tearDown();
parent::tearDown();
}

// -------------------------------------------------------------------------
// queue_notice / get_queued_notices
// -------------------------------------------------------------------------

public function test_queue_notice_stores_notice(): void {
$stored = null;
Functions\when( 'get_option' )->justReturn( array() );
Functions\when( 'update_option' )
->alias( static function ( $key, $value ) use ( &$stored ) {
$stored = $value;
return true;
} );

$manager = new IPCD_Notification_Manager();
$manager->queue_notice( 'Test message', 'error' );

$this->assertIsArray( $stored );
$this->assertCount( 1, $stored );
$this->assertSame( 'Test message', $stored[0]['message'] );
$this->assertSame( 'error', $stored[0]['type'] );
}

public function test_get_queued_notices_returns_array(): void {
Functions\when( 'get_option' )->justReturn( array() );

$manager = new IPCD_Notification_Manager();
$notices = $manager->get_queued_notices();
$this->assertIsArray( $notices );
}

// -------------------------------------------------------------------------
// get_settings / save_settings
// -------------------------------------------------------------------------

public function test_get_settings_returns_defaults_when_no_saved_settings(): void {
Functions\when( 'get_option' )->alias( static function ( $key, $default = false ) {
if ( 'admin_email' === $key ) {
return 'admin@example.com';
}
return array();
} );

$manager  = new IPCD_Notification_Manager();
$settings = $manager->get_settings();

$this->assertArrayHasKey( 'email_notifications', $settings );
$this->assertArrayHasKey( 'email_address', $settings );
$this->assertArrayHasKey( 'auto_rollback', $settings );
$this->assertArrayHasKey( 'scan_interval_hours', $settings );
$this->assertSame( false, $settings['email_notifications'] );
$this->assertSame( 6, $settings['scan_interval_hours'] );
}

public function test_save_settings_persists_sanitized_values(): void {
$saved_value = null;
Functions\when( 'update_option' )
->alias( static function ( $key, $value ) use ( &$saved_value ) {
$saved_value = $value;
return true;
} );

$manager = new IPCD_Notification_Manager();
$manager->save_settings( array(
'email_notifications' => '1',
'email_address'       => 'owner@shop.com',
'auto_rollback'       => '0',
'scan_interval_hours' => '12',
) );

$this->assertIsArray( $saved_value );
$this->assertTrue( $saved_value['email_notifications'] );
$this->assertSame( 'owner@shop.com', $saved_value['email_address'] );
$this->assertFalse( $saved_value['auto_rollback'] );
$this->assertSame( 12, $saved_value['scan_interval_hours'] );
}
}
