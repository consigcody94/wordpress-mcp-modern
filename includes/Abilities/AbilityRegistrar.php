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
	 * Already-registered abilities (e.g. WordPress core) to expose as tools,
	 * mapped to the MCP tool name to surface them under. These are not registered
	 * by us — core registers them on `wp_abilities_api_init`.
	 *
	 * @var array<string,string>
	 */
	private const EXTERNAL_TOOLS = array(
		'core/get-site-info'        => 'get_site_info',
		'core/get-user-info'        => 'get_user_info',
		'core/get-environment-info' => 'get_environment_info',
	);

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
			SettingsAbilities::class,
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

		// Only expose external (e.g. core) abilities that are actually registered
		// in this environment. wp_get_abilities() also forces the registry to
		// initialise, ensuring our own abilities are registered before the server
		// resolves them. Guarded so missing core abilities never trigger errors.
		$registered = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				$registered[ $ability->get_name() ] = true;
			}
		}
		foreach ( array_keys( self::EXTERNAL_TOOLS ) as $external_name ) {
			if ( isset( $registered[ $external_name ] ) ) {
				$names[] = $external_name;
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
			self::$name_map = self::EXTERNAL_TOOLS;
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
