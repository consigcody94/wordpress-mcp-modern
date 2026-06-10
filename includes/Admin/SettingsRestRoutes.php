<?php
declare(strict_types=1);

namespace WPMCP\Modern\Admin;

use WPMCP\Modern\Abilities\AbilityRegistrar;

/**
 * REST routes backing the React settings app:
 *   GET  /wpmcp/v1/settings  (manage_options) — settings, tool catalog + states
 *   POST /wpmcp/v1/settings  (manage_options) — persist settings and/or tool states
 *
 * Token management reuses the existing jwt-auth/v1 routes.
 */
final class SettingsRestRoutes {

	public const NS = 'wpmcp/v1';

	public static function register(): void {
		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( self::class, 'require_admin' ),
					'callback'            => array( self::class, 'get_settings' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( self::class, 'require_admin' ),
					'callback'            => array( self::class, 'save_settings' ),
				),
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function require_admin() {
		return current_user_can( 'manage_options' )
			? true
			: new \WP_Error(
				'wpmcp_forbidden',
				'Administrator capability required.',
				array( 'status' => rest_authorization_required_code() )
			);
	}

	public static function get_settings() {
		return rest_ensure_response( self::payload() );
	}

	public static function save_settings( \WP_REST_Request $request ) {
		$settings = $request->get_param( 'settings' );
		if ( is_array( $settings ) ) {
			// update_settings() coerces every known key (absent => false), so the
			// client must always send the complete settings object.
			SettingsStore::update_settings( $settings );
		}

		$states = $request->get_param( 'tool_states' );
		if ( is_array( $states ) ) {
			foreach ( AbilityRegistrar::tool_catalog() as $entry ) {
				if ( array_key_exists( $entry['tool'], $states ) ) {
					SettingsStore::set_tool_state( $entry['tool'], ! empty( $states[ $entry['tool'] ] ) );
				}
			}
		}

		return rest_ensure_response( self::payload() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function payload(): array {
		$tools = array();
		foreach ( AbilityRegistrar::tool_catalog() as $entry ) {
			$tools[] = array(
				'tool'    => $entry['tool'],
				'type'    => $entry['type'],
				'label'   => $entry['label'],
				'enabled' => SettingsStore::is_tool_enabled( $entry['tool'] ),
			);
		}

		return array(
			'settings' => SettingsStore::all(),
			'tools'    => $tools,
			'endpoint' => SettingsPage::endpoint_url(),
		);
	}
}
