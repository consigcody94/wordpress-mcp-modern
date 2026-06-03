<?php
/**
 * Plugin Name:       WordPress MCP (Modern)
 * Description:       Exposes WordPress capabilities to AI agents via the Abilities API and mcp-adapter.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       wordpress-mcp-modern
 *
 * @package WPMCP\Modern
 */

declare(strict_types=1);

namespace WPMCP\Modern;

defined( 'ABSPATH' ) || exit;

define( 'WPMCP_MODERN_VERSION', '0.1.0' );
define( 'WPMCP_MODERN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPMCP_MODERN_URL', plugin_dir_url( __FILE__ ) );

$wpmcp_modern_autoload = WPMCP_MODERN_PATH . 'vendor/autoload.php';
if ( ! is_readable( $wpmcp_modern_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'WordPress MCP (Modern): run composer install in the plugin directory.', 'wordpress-mcp-modern' )
			);
		}
	);
	return;
}
require_once $wpmcp_modern_autoload;

add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'WordPress MCP (Modern) requires the Abilities API (WordPress 6.9+).', 'wordpress-mcp-modern' )
					);
				}
			);
			return;
		}
		if ( ! class_exists( \WP\MCP\Core\McpAdapter::class ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'WordPress MCP (Modern): mcp-adapter library not found (composer install).', 'wordpress-mcp-modern' )
					);
				}
			);
			return;
		}

		// mcp-adapter is loaded as a Composer library, so its own plugin bootstrap
		// (mcp-adapter.php) never runs — boot the adapter ourselves.
		\WP\MCP\Core\McpAdapter::instance();

		Plugin::instance();
	}
);
