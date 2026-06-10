<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

use WPMCP\Modern\Admin\SettingsStore;

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
			CptAbilities::class,
			MediaAbilities::class,
			CommentsAbilities::class,
			SystemAbilities::class,
			WooAbilities::class,
		);
	}

	/**
	 * Register every group's abilities. Hooked on `wp_abilities_api_init`.
	 */
	public static function register_all(): void {
		foreach ( self::groups() as $group ) {
			$group::register();
		}
		ResourceAbilities::register();
		PromptAbilities::register();
		RestCrudAbilities::register();
	}

	/**
	 * Full ability names to expose as MCP resources on the server.
	 *
	 * @return string[]
	 */
	public static function resource_ability_names(): array {
		return SettingsStore::is_enabled() ? ResourceAbilities::names() : array();
	}

	/**
	 * Full ability names to expose as MCP prompts on the server.
	 *
	 * @return string[]
	 */
	public static function prompt_ability_names(): array {
		return SettingsStore::is_enabled() ? PromptAbilities::names() : array();
	}

	/**
	 * Filter callback: surface our prompt abilities under their slug (dropping the
	 * "<NS>/" prefix) so legacy prompt names (get-site-info, analyze-sales) hold.
	 *
	 * @param string      $name    Sanitized MCP prompt name.
	 * @param \WP_Ability $ability Source ability.
	 * @return string
	 */
	public static function map_prompt_name( $name, $ability ) {
		$ability_name = $ability->get_name();
		$prefix       = self::NS . '/';
		if ( 0 === strpos( $ability_name, $prefix ) ) {
			return substr( $ability_name, strlen( $prefix ) );
		}
		return $name;
	}

	/**
	 * Full ability names to expose as MCP tools on the server.
	 *
	 * @return string[]
	 */
	public static function tool_ability_names(): array {
		if ( ! SettingsStore::is_enabled() ) {
			return array();
		}

		// Experimental generic mode: replace the curated toolset with the three
		// REST-CRUD tools (matching legacy behaviour).
		if ( SettingsStore::rest_crud_mode() ) {
			return RestCrudAbilities::names();
		}

		$names = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group::definitions() as $def ) {
				$type      = $def['type'] ?? 'action';
				$tool_name = $def['mcp_name'] ?? $def['name'];
				if ( SettingsStore::is_type_enabled( $type ) && SettingsStore::is_tool_enabled( $tool_name ) ) {
					$names[] = $def['name'];
				}
			}
		}

		// External (e.g. core) abilities — read-only; included when registered in
		// this environment and not individually disabled. wp_get_abilities() also
		// forces the registry to initialise before the server resolves tools.
		$registered = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				$registered[ $ability->get_name() ] = true;
			}
		}
		foreach ( self::EXTERNAL_TOOLS as $external_name => $tool_name ) {
			if ( isset( $registered[ $external_name ] ) && SettingsStore::is_tool_enabled( $tool_name ) ) {
				$names[] = $external_name;
			}
		}

		return $names;
	}

	/**
	 * Catalog of curated + external tools for the admin UI (ignores gating):
	 * each entry is [ 'tool' => mcp name, 'type' => type, 'label' => label ].
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function tool_catalog(): array {
		$catalog = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group::definitions() as $def ) {
				$catalog[] = array(
					'tool'  => $def['mcp_name'] ?? $def['name'],
					'type'  => $def['type'] ?? 'action',
					'label' => $def['label'] ?? ( $def['mcp_name'] ?? $def['name'] ),
				);
			}
		}
		foreach ( self::EXTERNAL_TOOLS as $mcp_name ) {
			$catalog[] = array(
				'tool'  => $mcp_name,
				'type'  => 'read',
				'label' => $mcp_name,
			);
		}
		return $catalog;
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
			foreach ( RestCrudAbilities::definitions() as $def ) {
				if ( ! empty( $def['mcp_name'] ) ) {
					self::$name_map[ $def['name'] ] = $def['mcp_name'];
				}
			}
		}

		return self::$name_map[ $ability->get_name() ] ?? $name;
	}
}
