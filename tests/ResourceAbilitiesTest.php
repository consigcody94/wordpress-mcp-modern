<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Abilities\AbilityRegistrar;

/**
 * Coverage for the MCP resource abilities.
 */
final class ResourceAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function ability( string $name ) {
		foreach ( wp_get_abilities() as $ability ) {
			if ( $ability->get_name() === $name ) {
				return $ability;
			}
		}
		return null;
	}

	public function test_resource_abilities_registered(): void {
		$expected = array(
			'wordpress-mcp/resource-site-info',
			'wordpress-mcp/resource-plugin-info',
			'wordpress-mcp/resource-theme-info',
			'wordpress-mcp/resource-user-info',
			'wordpress-mcp/resource-site-settings',
		);
		foreach ( $expected as $name ) {
			$this->assertNotNull( $this->ability( $name ), "Resource not registered: {$name}" );
		}
		$this->assertCount( 5, AbilityRegistrar::resource_ability_names() );
	}

	public function test_resources_declare_uri_meta(): void {
		$meta = $this->ability( 'wordpress-mcp/resource-site-info' )->get_meta();
		$this->assertSame( 'wordpress://site-info', $meta['mcp']['uri'] ?? null );
		$this->assertSame( 'resource', $meta['mcp']['type'] ?? null );
	}

	public function test_site_info_resource_returns_data(): void {
		$data = $this->ability( 'wordpress-mcp/resource-site-info' )->execute( array() );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'name', $data );
		$this->assertArrayHasKey( 'url', $data );
	}

	public function test_site_settings_resource_is_grouped(): void {
		$data = $this->ability( 'wordpress-mcp/resource-site-settings' )->execute( array() );
		$this->assertArrayHasKey( 'general', $data );
		$this->assertArrayHasKey( 'reading', $data );
	}
}
