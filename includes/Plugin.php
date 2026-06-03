<?php
declare(strict_types=1);

namespace WPMCP\Modern;

use WPMCP\Modern\Abilities\AbilityRegistrar;
use WPMCP\Modern\Mcp\ServerProvider;

/**
 * Top-level orchestrator: registers the ability category and wires the MCP server.
 */
final class Plugin {

	public const ABILITY_CATEGORY = 'wordpress-mcp';

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( AbilityRegistrar::class, 'register_all' ) );
		add_filter( 'mcp_adapter_tool_name', array( AbilityRegistrar::class, 'map_tool_name' ), 10, 2 );
		add_action( 'mcp_adapter_init', array( ServerProvider::class, 'create' ) );
	}

	public function register_category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				self::ABILITY_CATEGORY,
				array(
					'label'       => __( 'WordPress MCP', 'wordpress-mcp-modern' ),
					'description' => __( 'Capabilities exposed by the WordPress MCP (Modern) plugin.', 'wordpress-mcp-modern' ),
				)
			);
		}
	}
}
