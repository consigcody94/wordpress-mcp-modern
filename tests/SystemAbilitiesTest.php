<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;

/**
 * Coverage for plugin/theme management abilities, including the
 * self-deactivation guard and capability gating.
 */
final class SystemAbilitiesTest extends WP_UnitTestCase {

	private const TEMP_PLUGIN = 'wpmcp-temp-plugin.php';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$file = WP_PLUGIN_DIR . '/' . self::TEMP_PLUGIN;
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
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

	private function create_temp_plugin(): string {
		file_put_contents(
			WP_PLUGIN_DIR . '/' . self::TEMP_PLUGIN,
			"<?php\n/**\n * Plugin Name: WPMCP Temp Plugin\n * Version: 1.0\n */\n"
		);
		wp_cache_delete( 'plugins', 'plugins' );
		return self::TEMP_PLUGIN;
	}

	public function test_system_abilities_are_registered(): void {
		$expected = array(
			'wordpress-mcp/wp-list-plugins',
			'wordpress-mcp/wp-activate-plugin',
			'wordpress-mcp/wp-deactivate-plugin',
			'wordpress-mcp/wp-list-themes',
			'wordpress-mcp/wp-activate-theme',
		);
		foreach ( $expected as $name ) {
			$this->assertNotNull( $this->ability( $name ), "Ability not registered: {$name}" );
		}
	}

	public function test_plugin_activate_deactivate_round_trip(): void {
		$plugin = $this->create_temp_plugin();

		$plugins = $this->ability( 'wordpress-mcp/wp-list-plugins' )->execute( array() );
		$by_file = array_column( $plugins, null, 'plugin' );
		$this->assertArrayHasKey( $plugin, $by_file );
		$this->assertFalse( $by_file[ $plugin ]['active'] );

		$activated = $this->ability( 'wordpress-mcp/wp-activate-plugin' )->execute( array( 'plugin' => $plugin ) );
		$this->assertTrue( $activated['active'] ?? false );

		$deactivated = $this->ability( 'wordpress-mcp/wp-deactivate-plugin' )->execute( array( 'plugin' => $plugin ) );
		$this->assertFalse( $deactivated['active'] ?? true );
	}

	public function test_refuses_to_deactivate_itself(): void {
		$self   = plugin_basename( WPMCP_MODERN_PATH . 'wordpress-mcp-modern.php' );
		$result = $this->ability( 'wordpress-mcp/wp-deactivate-plugin' )->execute( array( 'plugin' => $self ) );
		$this->assertSame( 'cannot_deactivate_self', $result['error'] ?? null );
	}

	public function test_unknown_plugin_is_rejected(): void {
		$result = $this->ability( 'wordpress-mcp/wp-activate-plugin' )->execute( array( 'plugin' => 'nope/nope.php' ) );
		$this->assertSame( 'not_found', $result['error'] ?? null );
	}

	public function test_theme_listing_and_activation(): void {
		$themes = $this->ability( 'wordpress-mcp/wp-list-themes' )->execute( array() );
		$this->assertNotEmpty( $themes );
		$active = array_values( array_filter( $themes, static fn( $t ) => $t['active'] ) );
		$this->assertCount( 1, $active, 'exactly one theme should be active' );

		$result = $this->ability( 'wordpress-mcp/wp-activate-theme' )->execute(
			array( 'stylesheet' => $active[0]['stylesheet'] )
		);
		$this->assertTrue( $result['active'] ?? false );

		$missing = $this->ability( 'wordpress-mcp/wp-activate-theme' )->execute( array( 'stylesheet' => 'no-such-theme' ) );
		$this->assertSame( 'not_found', $missing['error'] ?? null );
	}

	public function test_system_tools_require_admin_capabilities(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertNotTrue( $this->ability( 'wordpress-mcp/wp-activate-plugin' )->check_permissions( array() ) );
		$this->assertNotTrue( $this->ability( 'wordpress-mcp/wp-activate-theme' )->check_permissions( array() ) );
	}
}
