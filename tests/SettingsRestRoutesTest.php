<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Admin\SettingsStore;

/**
 * The wpmcp/v1 settings routes (backing the React settings app) read and
 * persist settings + tool states, and enforce manage_options.
 */
final class SettingsRestRoutesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		delete_option( SettingsStore::SETTINGS );
		delete_option( SettingsStore::TOOL_STATES );
		parent::tear_down();
	}

	public function test_get_settings_returns_payload(): void {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wpmcp/v1/settings' ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['settings']['enabled'] );
		$this->assertArrayHasKey( 'enable_rest_api_crud_tools', $data['settings'] );
		$this->assertStringContainsString( 'wpmcp/mcp', $data['endpoint'] );

		$tools = array_column( $data['tools'], null, 'tool' );
		$this->assertArrayHasKey( 'wp_posts_search', $tools );
		$this->assertTrue( $tools['wp_posts_search']['enabled'] );
		$this->assertSame( 'read', $tools['wp_posts_search']['type'] );
	}

	public function test_post_persists_settings_and_tool_states(): void {
		$request = new \WP_REST_Request( 'POST', '/wpmcp/v1/settings' );
		$request->set_body_params(
			array(
				'settings'    => array(
					'enabled'             => '1',
					'enable_create_tools' => '1',
					'enable_update_tools' => '1',
					// enable_delete_tools omitted => coerced to false.
				),
				'tool_states' => array( 'wp_posts_search' => false ),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->assertFalse( SettingsStore::get( 'enable_delete_tools' ) );
		$this->assertTrue( SettingsStore::get( 'enable_create_tools' ) );
		$this->assertFalse( SettingsStore::is_tool_enabled( 'wp_posts_search' ) );

		$tools = array_column( $response->get_data()['tools'], null, 'tool' );
		$this->assertFalse( $tools['wp_posts_search']['enabled'], 'response payload should reflect the saved state' );
	}

	public function test_routes_require_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$get = rest_do_request( new \WP_REST_Request( 'GET', '/wpmcp/v1/settings' ) );
		$this->assertSame( 403, $get->get_status() );

		$post = new \WP_REST_Request( 'POST', '/wpmcp/v1/settings' );
		$post->set_body_params( array( 'settings' => array() ) );
		$this->assertSame( 403, rest_do_request( $post )->get_status() );
	}
}
