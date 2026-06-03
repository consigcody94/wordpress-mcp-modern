<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Admin\SettingsStore;

/**
 * Coverage for the generic REST-CRUD tools (incl. the get_function_details
 * fix and per-method gating).
 */
final class RestCrudAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		delete_option( SettingsStore::SETTINGS );
		parent::tear_down();
	}

	private function ability( string $name ) {
		foreach ( wp_get_abilities() as $ability ) {
			if ( $ability->get_name() === $name ) {
				return $ability;
			}
		}
		return null;
	}

	public function test_rest_crud_abilities_registered(): void {
		$this->assertNotNull( $this->ability( 'wordpress-mcp/list-api-functions' ) );
		$this->assertNotNull( $this->ability( 'wordpress-mcp/get-function-details' ) );
		$this->assertNotNull( $this->ability( 'wordpress-mcp/run-api-function' ) );
	}

	public function test_list_functions_excludes_sensitive_routes(): void {
		$result = $this->ability( 'wordpress-mcp/list-api-functions' )->execute( array() );
		$this->assertArrayHasKey( 'routes', $result );
		foreach ( array_keys( $result['routes'] ) as $route ) {
			$this->assertStringNotContainsStringIgnoringCase( 'jwt-auth', $route );
			$this->assertStringNotContainsStringIgnoringCase( 'oembed', $route );
		}
	}

	public function test_get_function_details_returns_requested_route(): void {
		// Regression guard for the legacy variable-shadowing bug that always
		// returned the first route's args.
		$result = $this->ability( 'wordpress-mcp/get-function-details' )->execute(
			array(
				'route'  => '/wp/v2/posts',
				'method' => 'GET',
			)
		);
		$this->assertSame( '/wp/v2/posts', $result['route'] ?? null );
		$this->assertArrayHasKey( 'args', $result );
	}

	public function test_run_respects_delete_setting(): void {
		update_option(
			SettingsStore::SETTINGS,
			array(
				'enabled'             => true,
				'enable_delete_tools' => false,
			)
		);
		$result = $this->ability( 'wordpress-mcp/run-api-function' )->execute(
			array(
				'route'  => '/wp/v2/posts/1',
				'method' => 'DELETE',
			)
		);
		$this->assertSame( 'operation_disabled', $result['error'] ?? null );
	}

	public function test_run_executes_a_read(): void {
		$result = $this->ability( 'wordpress-mcp/run-api-function' )->execute(
			array(
				'route'  => '/wp/v2/posts',
				'method' => 'GET',
				'data'   => array( 'per_page' => 1 ),
			)
		);
		$this->assertArrayHasKey( 'status', $result );
		$this->assertSame( 200, $result['status'] );
	}
}
