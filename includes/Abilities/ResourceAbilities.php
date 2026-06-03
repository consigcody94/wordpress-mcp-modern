<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * MCP resources mirroring the legacy wordpress-mcp resources. All are
 * `manage_options`-gated (parity) and return JSON data.
 */
final class ResourceAbilities {

	public static function register(): void {
		foreach ( self::definitions() as $def ) {
			ResourceAbility::register( $def );
		}
	}

	/**
	 * @return string[]
	 */
	public static function names(): array {
		return array_column( self::definitions(), 'name' );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		$ns = AbilityRegistrar::NS;

		return array(
			array(
				'name'        => "$ns/resource-site-info",
				'uri'         => 'wordpress://site-info',
				'label'       => 'Site info',
				'description' => 'Core information about this WordPress site.',
				'capability'  => 'manage_options',
				'execute'     => static function () {
					return array(
						'name'        => get_bloginfo( 'name' ),
						'url'         => get_bloginfo( 'url' ),
						'description' => get_bloginfo( 'description' ),
						'admin_email' => get_bloginfo( 'admin_email' ),
						'version'     => get_bloginfo( 'version' ),
						'language'    => get_bloginfo( 'language' ),
						'timezone'    => wp_timezone_string(),
					);
				},
			),
			array(
				'name'        => "$ns/resource-plugin-info",
				'uri'         => 'wordpress://plugin-info',
				'label'       => 'Plugin info',
				'description' => 'Installed and active plugins.',
				'capability'  => 'manage_options',
				'execute'     => static function () {
					if ( ! function_exists( 'get_plugins' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					return array(
						'active' => (array) get_option( 'active_plugins', array() ),
						'all'    => get_plugins(),
					);
				},
			),
			array(
				'name'        => "$ns/resource-theme-info",
				'uri'         => 'wordpress://theme-info',
				'label'       => 'Theme info',
				'description' => 'The active theme.',
				'capability'  => 'manage_options',
				'execute'     => static function () {
					$theme = wp_get_theme();
					return array(
						'name'       => $theme->get( 'Name' ),
						'version'    => $theme->get( 'Version' ),
						'author'     => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
						'template'   => $theme->get_template(),
						'stylesheet' => $theme->get_stylesheet(),
					);
				},
			),
			array(
				'name'        => "$ns/resource-user-info",
				'uri'         => 'wordpress://user-info',
				'label'       => 'User info',
				'description' => 'User counts by role.',
				'capability'  => 'manage_options',
				'execute'     => static function () {
					$counts = count_users();
					return array(
						'total'   => $counts['total_users'],
						'by_role' => $counts['avail_roles'],
					);
				},
			),
			array(
				'name'        => "$ns/resource-site-settings",
				'uri'         => 'wordpress://site-settings',
				'label'       => 'Site settings',
				'description' => 'Grouped site option values (general, reading, discussion, media).',
				'capability'  => 'manage_options',
				'execute'     => static function () {
					return array(
						'general'    => array(
							'blogname'        => get_option( 'blogname' ),
							'blogdescription' => get_option( 'blogdescription' ),
							'admin_email'     => get_option( 'admin_email' ),
							'timezone_string' => get_option( 'timezone_string' ),
							'date_format'     => get_option( 'date_format' ),
							'time_format'     => get_option( 'time_format' ),
							'start_of_week'   => (int) get_option( 'start_of_week' ),
						),
						'reading'    => array(
							'posts_per_page' => (int) get_option( 'posts_per_page' ),
							'show_on_front'  => get_option( 'show_on_front' ),
							'page_on_front'  => (int) get_option( 'page_on_front' ),
						),
						'discussion' => array(
							'default_comment_status' => get_option( 'default_comment_status' ),
							'default_ping_status'    => get_option( 'default_ping_status' ),
						),
						'media'      => array(
							'thumbnail_size_w' => (int) get_option( 'thumbnail_size_w' ),
							'thumbnail_size_h' => (int) get_option( 'thumbnail_size_h' ),
						),
					);
				},
			),
		);
	}
}
