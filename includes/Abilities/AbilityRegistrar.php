<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * Central registry of the plugin's ability groups.
 *
 * Each group class exposes static `register()` (registers its abilities) and
 * `definitions()` (the declarative tool config). This is the single source of
 * truth used both at `wp_abilities_api_init` and when the MCP server is created,
 * so the two never drift.
 *
 * Note: WordPress ability names must be lowercase and may only contain
 * alphanumerics, dashes and the forward slash (no underscores). The legacy
 * wordpress-mcp tool names used underscores, so each definition may carry an
 * `mcp_name` that we restore via the `mcp_adapter_tool_name` filter.
 */
final class AbilityRegistrar {

	public const NS = 'wordpress-mcp';

	/**
	 * Cache of ability-name => legacy MCP tool-name.
	 *
	 * @var array<string,string>|null
	 */
	private static $name_map = null;

	/**
	 * Ability group classes. New phases append their group here.
	 *
	 * @return array<class-string>
	 */
	private static function groups(): array {
		return array(
			ContentAbilities::class,
			TaxonomyAbilities::class,
			UsersAbilities::class,
		);
	}

	/**
	 * Register every group's abilities. Hooked on `wp_abilities_api_init`.
	 */
	public static function register_all(): void {
		foreach ( self::groups() as $group ) {
			$group::register();
		}
	}

	/**
	 * Full ability names to expose as MCP tools on the server.
	 *
	 * @return string[]
	 */
	public static function tool_ability_names(): array {
		$names = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group::definitions() as $def ) {
				$names[] = $def['name'];
			}
		}
		return $names;
	}

	/**
	 * Filter callback: restore the legacy (underscore) MCP tool name for our
	 * abilities. Falls back to the sanitized name for anything we don't own.
	 *
	 * @param string      $name    Sanitized MCP tool name.
	 * @param \WP_Ability $ability Source ability.
	 * @return string
	 */
	public static function map_tool_name( $name, $ability ) {
		if ( null === self::$name_map ) {
			self::$name_map = array();
			foreach ( self::groups() as $group ) {
				foreach ( $group::definitions() as $def ) {
					if ( ! empty( $def['mcp_name'] ) ) {
						self::$name_map[ $def['name'] ] = $def['mcp_name'];
					}
				}
			}
		}

		return self::$name_map[ $ability->get_name() ] ?? $name;
	}
}
