<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Abilities\AbilityRegistrar;

/**
 * Settings abilities + reuse of WordPress core abilities as tools.
 */
final class SettingsAbilitiesTest extends WP_UnitTestCase {

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

	public function test_settings_abilities_registered(): void {
		$this->assertNotNull( $this->ability( 'wordpress-mcp/wp-get-general-settings' ) );
		$this->assertNotNull( $this->ability( 'wordpress-mcp/wp-update-general-settings' ) );
	}

	public function test_update_then_get_settings_round_trips(): void {
		$this->ability( 'wordpress-mcp/wp-update-general-settings' )->execute(
			array( 'title' => 'MCP Test Site' )
		);
		$got = $this->ability( 'wordpress-mcp/wp-get-general-settings' )->execute( array() );
		$this->assertIsArray( $got );
		$this->assertSame( 'MCP Test Site', $got['title'] );
	}

	public function test_core_ability_name_mapping(): void {
		// External (core) abilities are surfaced under friendly tool names. This
		// mapping holds regardless of whether core registered them in this env.
		$stub = new class() {
			public function get_name(): string {
				return 'core/get-site-info';
			}
		};
		$this->assertSame(
			'get_site_info',
			AbilityRegistrar::map_tool_name( 'core-get-site-info', $stub )
		);

		// Our own settings ability is always advertised as a tool.
		$this->assertContains(
			'wordpress-mcp/wp-get-general-settings',
			AbilityRegistrar::tool_ability_names()
		);
	}
}
