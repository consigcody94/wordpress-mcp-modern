<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Admin\SettingsStore;
use WPMCP\Modern\Observability\AuditLog;

/**
 * Audit log + rate limiting, exercised through the mcp-adapter filters the
 * plugin hooks (mcp_adapter_tool_call_result / mcp_adapter_pre_tool_call).
 */
final class AuditLogTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		AuditLog::clear();
		delete_option( SettingsStore::SETTINGS );
		remove_all_filters( 'wpmcp_rate_limit_per_minute' );
		parent::tear_down();
	}

	private function enable( string $key ): void {
		$settings         = SettingsStore::all();
		$settings[ $key ] = true;
		SettingsStore::update_settings( $settings );
	}

	public function test_audit_log_disabled_by_default(): void {
		apply_filters( 'mcp_adapter_tool_call_result', array( 'ok' => true ), array(), 'wp_posts_search' );
		$this->assertSame( array(), AuditLog::entries() );
	}

	public function test_audit_log_records_calls_and_outcomes(): void {
		$this->enable( 'enable_audit_log' );

		apply_filters( 'mcp_adapter_tool_call_result', array( 'id' => 1 ), array(), 'wp_posts_search' );
		apply_filters( 'mcp_adapter_tool_call_result', array( 'error' => 'not_found' ), array(), 'wp_get_post' );

		$entries = AuditLog::entries();
		$this->assertCount( 2, $entries );
		$this->assertSame( 'wp_posts_search', $entries[0]['tool'] );
		$this->assertTrue( $entries[0]['ok'] );
		$this->assertSame( 'wp_get_post', $entries[1]['tool'] );
		$this->assertFalse( $entries[1]['ok'], 'error-array results should be recorded as failures' );
		$this->assertSame( get_current_user_id(), $entries[0]['user_id'] );
	}

	public function test_audit_log_is_a_ring_buffer(): void {
		$this->enable( 'enable_audit_log' );
		add_filter( 'wpmcp_audit_log_max_entries', static fn() => 3 );

		for ( $i = 1; $i <= 5; $i++ ) {
			apply_filters( 'mcp_adapter_tool_call_result', array(), array(), "tool_{$i}" );
		}
		remove_all_filters( 'wpmcp_audit_log_max_entries' );

		$entries = AuditLog::entries();
		$this->assertCount( 3, $entries );
		$this->assertSame( 'tool_3', $entries[0]['tool'], 'oldest entries should be dropped' );
		$this->assertSame( 'tool_5', $entries[2]['tool'] );
	}

	public function test_rate_limiting_disabled_by_default(): void {
		add_filter( 'wpmcp_rate_limit_per_minute', static fn() => 1 );
		$this->assertIsArray( apply_filters( 'mcp_adapter_pre_tool_call', array(), 'x' ) );
		$this->assertIsArray( apply_filters( 'mcp_adapter_pre_tool_call', array(), 'x' ) );
	}

	public function test_rate_limit_short_circuits_with_429(): void {
		$this->enable( 'enable_rate_limiting' );
		add_filter( 'wpmcp_rate_limit_per_minute', static fn() => 2 );

		$this->assertIsArray( apply_filters( 'mcp_adapter_pre_tool_call', array( 'a' => 1 ), 'x' ) );
		$this->assertIsArray( apply_filters( 'mcp_adapter_pre_tool_call', array( 'a' => 1 ), 'x' ) );

		$third = apply_filters( 'mcp_adapter_pre_tool_call', array( 'a' => 1 ), 'x' );
		$this->assertWPError( $third );
		$this->assertSame( 'wpmcp_rate_limited', $third->get_error_code() );
		$this->assertSame( 429, $third->get_error_data()['status'] );
	}

	public function test_audit_rest_route_exposes_entries(): void {
		$this->enable( 'enable_audit_log' );
		apply_filters( 'mcp_adapter_tool_call_result', array(), array(), 'wp_posts_search' );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wpmcp/v1/audit' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['enabled'] );
		$this->assertSame( 'wp_posts_search', $data['entries'][0]['tool'] );
	}
}
