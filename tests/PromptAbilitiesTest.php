<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Abilities\AbilityRegistrar;

/**
 * Coverage for the MCP prompt abilities.
 */
final class PromptAbilitiesTest extends WP_UnitTestCase {

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

	public function test_prompt_abilities_registered(): void {
		$this->assertNotNull( $this->ability( 'wordpress-mcp/get-site-info' ) );
		$this->assertNotNull( $this->ability( 'wordpress-mcp/analyze-sales' ) );
		$this->assertCount( 2, AbilityRegistrar::prompt_ability_names() );
	}

	public function test_prompt_declares_prompt_meta(): void {
		$meta = $this->ability( 'wordpress-mcp/get-site-info' )->get_meta();
		$this->assertSame( 'prompt', $meta['mcp']['type'] ?? null );
		$this->assertSame( 'info_type', $meta['mcp']['arguments'][0]['name'] ?? null );
	}

	public function test_get_site_info_prompt_renders_optional_focus(): void {
		$result = $this->ability( 'wordpress-mcp/get-site-info' )->execute( array( 'info_type' => 'plugins' ) );
		$text   = $result['messages'][0]['content']['text'] ?? '';
		$this->assertStringContainsString( 'Focus especially on: plugins', $text );
	}

	public function test_analyze_sales_prompt_includes_span(): void {
		$result = $this->ability( 'wordpress-mcp/analyze-sales' )->execute( array( 'time_span' => 'last 7 days' ) );
		$text   = $result['messages'][0]['content']['text'] ?? '';
		$this->assertStringContainsString( 'last 7 days', $text );
	}

	public function test_prompt_name_mapping_strips_namespace(): void {
		$stub = new class() {
			public function get_name(): string {
				return 'wordpress-mcp/analyze-sales';
			}
		};
		$this->assertSame( 'analyze-sales', AbilityRegistrar::map_prompt_name( 'x', $stub ) );
	}
}
