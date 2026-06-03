<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Abilities\AbilityRegistrar;
use WPMCP\Modern\Admin\SettingsStore;

/**
 * Settings-driven gating of the exposed tool/resource/prompt lists.
 */
final class GatingTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		delete_option( SettingsStore::SETTINGS );
		delete_option( SettingsStore::TOOL_STATES );
		parent::tear_down();
	}

	public function test_defaults_expose_curated_tools(): void {
		$names = AbilityRegistrar::tool_ability_names();
		$this->assertContains( 'wordpress-mcp/wp-add-post', $names );
		$this->assertContains( 'wordpress-mcp/wp-delete-post', $names );
		$this->assertNotContains( 'wordpress-mcp/run-api-function', $names );
	}

	public function test_master_disable_hides_everything(): void {
		update_option( SettingsStore::SETTINGS, array( 'enabled' => false ) );
		$this->assertSame( array(), AbilityRegistrar::tool_ability_names() );
		$this->assertSame( array(), AbilityRegistrar::resource_ability_names() );
		$this->assertSame( array(), AbilityRegistrar::prompt_ability_names() );
	}

	public function test_disabling_delete_type_hides_delete_tools(): void {
		update_option(
			SettingsStore::SETTINGS,
			array(
				'enabled'             => true,
				'enable_create_tools' => true,
				'enable_update_tools' => true,
				'enable_delete_tools' => false,
			)
		);
		$names = AbilityRegistrar::tool_ability_names();
		$this->assertContains( 'wordpress-mcp/wp-add-post', $names );
		$this->assertNotContains( 'wordpress-mcp/wp-delete-post', $names );
	}

	public function test_per_tool_toggle_hides_one_tool(): void {
		SettingsStore::set_tool_state( 'wp_get_post', false );
		$names = AbilityRegistrar::tool_ability_names();
		$this->assertNotContains( 'wordpress-mcp/wp-get-post', $names );
		$this->assertContains( 'wordpress-mcp/wp-posts-search', $names );
	}

	public function test_rest_crud_mode_swaps_toolset(): void {
		update_option(
			SettingsStore::SETTINGS,
			array(
				'enabled'                    => true,
				'enable_rest_api_crud_tools' => true,
			)
		);
		$names = AbilityRegistrar::tool_ability_names();
		$this->assertContains( 'wordpress-mcp/run-api-function', $names );
		$this->assertContains( 'wordpress-mcp/list-api-functions', $names );
		$this->assertNotContains( 'wordpress-mcp/wp-add-post', $names );
	}
}
