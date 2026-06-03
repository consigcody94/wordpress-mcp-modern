<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * General site settings capabilities (wp-admin "General Settings"), mirroring the
 * legacy wordpress-mcp settings tools. Backed by the core /wp/v2/settings route,
 * which uses POST for updates and requires `manage_options`.
 */
final class SettingsAbilities {

	public static function register(): void {
		foreach ( self::definitions() as $def ) {
			RestProxyAbility::register( $def );
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		$ns = AbilityRegistrar::NS;

		return array(
			array(
				'name'         => "$ns/wp-get-general-settings",
				'mcp_name'     => 'wp_get_general_settings',
				'label'        => 'Get general settings',
				'description'  => 'Retrieve the site\'s general settings (title, tagline, timezone, formats, etc.).',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/settings',
				'capability'   => 'manage_options',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
			),
			array(
				'name'         => "$ns/wp-update-general-settings",
				'mcp_name'     => 'wp_update_general_settings',
				'label'        => 'Update general settings',
				'description'  => 'Update the site\'s general settings.',
				'type'         => 'update',
				'method'       => 'POST',
				'route'        => '/wp/v2/settings',
				'capability'   => 'manage_options',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'                  => array( 'type' => 'string', 'description' => 'Site title.' ),
						'description'            => array( 'type' => 'string', 'description' => 'Site tagline.' ),
						'timezone_string'        => array( 'type' => 'string', 'description' => 'PHP timezone identifier, e.g. "America/New_York".' ),
						'date_format'            => array( 'type' => 'string' ),
						'time_format'            => array( 'type' => 'string' ),
						'start_of_week'          => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 6 ),
						'language'               => array( 'type' => 'string', 'description' => 'WPLANG locale code.' ),
						'use_smilies'            => array( 'type' => 'boolean' ),
						'default_category'       => array( 'type' => 'integer' ),
						'default_post_format'    => array( 'type' => 'string' ),
						'posts_per_page'         => array( 'type' => 'integer', 'minimum' => 1 ),
						'default_comment_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ),
						'default_ping_status'    => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ),
					),
				),
			),
		);
	}
}
