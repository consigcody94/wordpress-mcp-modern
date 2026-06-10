<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * Site administration capabilities: list/activate/deactivate plugins and
 * list/activate themes. Native abilities (the core REST plugins route can't be
 * proxied through rest_do_request because plugin identifiers contain slashes,
 * and core exposes no REST route for theme switching at all). Heavily gated:
 * activate_plugins / switch_themes capabilities, and the plugin refuses to
 * deactivate itself so an agent can't cut its own connection.
 */
final class SystemAbilities {

	public static function register(): void {
		foreach ( self::definitions() as $def ) {
			NativeAbility::register( $def );
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		$ns = AbilityRegistrar::NS;

		return array(
			array(
				'name'         => "$ns/wp-list-plugins",
				'mcp_name'     => 'wp_list_plugins',
				'kind'         => 'native',
				'label'        => 'List plugins',
				'description'  => 'List installed plugins with name, version, and active state.',
				'type'         => 'read',
				'capability'   => 'activate_plugins',
				'input_schema' => array( 'type' => 'object', 'properties' => array() ),
				'execute'      => static function () {
					self::load_plugin_functions();
					$out = array();
					foreach ( get_plugins() as $file => $data ) {
						$out[] = array(
							'plugin'  => $file,
							'name'    => $data['Name'] ?? $file,
							'version' => $data['Version'] ?? '',
							'active'  => is_plugin_active( $file ),
						);
					}
					return $out;
				},
			),
			array(
				'name'         => "$ns/wp-activate-plugin",
				'mcp_name'     => 'wp_activate_plugin',
				'kind'         => 'native',
				'label'        => 'Activate plugin',
				'description'  => 'Activate an installed plugin by its plugin file (e.g. "akismet/akismet.php").',
				'type'         => 'action',
				'capability'   => 'activate_plugins',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array( 'type' => 'string', 'description' => 'Plugin file relative to the plugins directory.' ),
					),
					'required'   => array( 'plugin' ),
				),
				'execute'      => static function ( array $input ) {
					self::load_plugin_functions();
					$plugin = (string) ( $input['plugin'] ?? '' );
					if ( ! array_key_exists( $plugin, get_plugins() ) ) {
						return array( 'error' => 'not_found', 'message' => 'Plugin not found: ' . $plugin );
					}
					$result = activate_plugin( $plugin );
					if ( is_wp_error( $result ) ) {
						return array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() );
					}
					return array( 'plugin' => $plugin, 'active' => is_plugin_active( $plugin ) );
				},
			),
			array(
				'name'         => "$ns/wp-deactivate-plugin",
				'mcp_name'     => 'wp_deactivate_plugin',
				'kind'         => 'native',
				'label'        => 'Deactivate plugin',
				'description'  => 'Deactivate an active plugin by its plugin file. Refuses to deactivate the MCP plugin itself.',
				'type'         => 'action',
				'capability'   => 'activate_plugins',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array( 'type' => 'string', 'description' => 'Plugin file relative to the plugins directory.' ),
					),
					'required'   => array( 'plugin' ),
				),
				'execute'      => static function ( array $input ) {
					self::load_plugin_functions();
					$plugin = (string) ( $input['plugin'] ?? '' );
					if ( plugin_basename( WPMCP_MODERN_PATH . 'wordpress-mcp-modern.php' ) === $plugin ) {
						return array( 'error' => 'cannot_deactivate_self', 'message' => 'Refusing to deactivate the MCP plugin over MCP.' );
					}
					if ( ! array_key_exists( $plugin, get_plugins() ) ) {
						return array( 'error' => 'not_found', 'message' => 'Plugin not found: ' . $plugin );
					}
					deactivate_plugins( $plugin );
					return array( 'plugin' => $plugin, 'active' => is_plugin_active( $plugin ) );
				},
			),
			array(
				'name'         => "$ns/wp-list-themes",
				'mcp_name'     => 'wp_list_themes',
				'kind'         => 'native',
				'label'        => 'List themes',
				'description'  => 'List installed themes with name, version, and which one is active.',
				'type'         => 'read',
				'capability'   => 'switch_themes',
				'input_schema' => array( 'type' => 'object', 'properties' => array() ),
				'execute'      => static function () {
					$active = get_stylesheet();
					$out    = array();
					foreach ( wp_get_themes() as $stylesheet => $theme ) {
						$out[] = array(
							'stylesheet' => $stylesheet,
							'name'       => $theme->get( 'Name' ),
							'version'    => (string) $theme->get( 'Version' ),
							'active'     => $stylesheet === $active,
						);
					}
					return $out;
				},
			),
			array(
				'name'         => "$ns/wp-activate-theme",
				'mcp_name'     => 'wp_activate_theme',
				'kind'         => 'native',
				'label'        => 'Activate theme',
				'description'  => 'Switch the active theme by stylesheet slug (e.g. "twentytwentyfive").',
				'type'         => 'action',
				'capability'   => 'switch_themes',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet' => array( 'type' => 'string', 'description' => 'Theme directory slug.' ),
					),
					'required'   => array( 'stylesheet' ),
				),
				'execute'      => static function ( array $input ) {
					$stylesheet = (string) ( $input['stylesheet'] ?? '' );
					$theme      = wp_get_theme( $stylesheet );
					if ( ! $theme->exists() ) {
						return array( 'error' => 'not_found', 'message' => 'Theme not found: ' . $stylesheet );
					}
					switch_theme( $stylesheet );
					return array( 'stylesheet' => $stylesheet, 'active' => get_stylesheet() === $stylesheet );
				},
			),
		);
	}

	private static function load_plugin_functions(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
