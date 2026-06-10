<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Admin\SettingsPage;

/**
 * The admin settings screen renders its controls and enforces capabilities.
 */
final class SettingsPageTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
	}

	private function capture_render(): string {
		ob_start();
		SettingsPage::render();
		return (string) ob_get_clean();
	}

	public function test_render_outputs_expected_controls(): void {
		$html = $this->capture_render();
		$this->assertStringContainsString( 'WordPress MCP', $html );
		$this->assertStringContainsString( 'wpmcp/mcp', $html, 'should show the MCP endpoint URL' );
		$this->assertStringContainsString( 'enable_rest_api_crud_tools', $html, 'should expose the REST-CRUD toggle' );
		$this->assertStringContainsString( 'wp_posts_search', $html, 'should list curated tools' );
		$this->assertStringContainsString( 'Authentication tokens', $html, 'should include the token panel' );
		$this->assertStringContainsString( 'wpmcp-settings-app', $html, 'should render the React app mount point' );
		$this->assertStringContainsString( 'wpmcp-legacy-settings', $html, 'should keep the no-JS fallback form' );
	}

	public function test_render_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', $this->capture_render() );
	}
}
