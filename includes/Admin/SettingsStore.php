<?php
declare(strict_types=1);

namespace WPMCP\Modern\Admin;

/**
 * Single source of truth for plugin settings and per-tool toggles, and the
 * gating decisions derived from them. Option names mirror the legacy plugin for
 * familiarity. Defaults keep everything on (except the experimental REST-CRUD
 * mode) so the tool surface is available out of the box.
 */
final class SettingsStore {

	public const SETTINGS    = 'wordpress_mcp_settings';
	public const TOOL_STATES = 'wordpress_mcp_tool_states';

	/**
	 * @return array<string,bool>
	 */
	public static function defaults(): array {
		return array(
			'enabled'                    => true,
			'enable_create_tools'        => true,
			'enable_update_tools'        => true,
			'enable_delete_tools'        => true,
			'enable_rest_api_crud_tools' => false,
			'enable_audit_log'           => false,
			'enable_rate_limiting'       => false,
			'enable_oauth'               => false,
		);
	}

	/**
	 * @return array<string,bool>
	 */
	public static function all(): array {
		$saved = get_option( self::SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( string $key ): bool {
		$all = self::all();
		return ! empty( $all[ $key ] );
	}

	public static function is_enabled(): bool {
		// Multisite: the network kill switch overrides per-site settings.
		if ( is_multisite() && ! (bool) get_site_option( NetworkSettingsPage::OPTION, true ) ) {
			return false;
		}
		return self::get( 'enabled' );
	}

	public static function rest_crud_mode(): bool {
		return self::get( 'enable_rest_api_crud_tools' );
	}

	public static function is_type_enabled( string $type ): bool {
		switch ( $type ) {
			case 'create':
				return self::get( 'enable_create_tools' );
			case 'update':
				return self::get( 'enable_update_tools' );
			case 'delete':
				return self::get( 'enable_delete_tools' );
			default: // read, action.
				return true;
		}
	}

	/**
	 * @return array<string,bool>
	 */
	public static function tool_states(): array {
		$states = get_option( self::TOOL_STATES, array() );
		return is_array( $states ) ? $states : array();
	}

	/** Per-tool toggle is default-on. */
	public static function is_tool_enabled( string $tool_name ): bool {
		$states = self::tool_states();
		return ! array_key_exists( $tool_name, $states ) || ! empty( $states[ $tool_name ] );
	}

	/**
	 * Persist settings from a raw (e.g. form) array, coercing to known booleans.
	 *
	 * @param array<string,mixed> $values
	 */
	public static function update_settings( array $values ): void {
		$clean = array();
		foreach ( array_keys( self::defaults() ) as $key ) {
			$clean[ $key ] = ! empty( $values[ $key ] );
		}
		update_option( self::SETTINGS, $clean );
	}

	public static function set_tool_state( string $tool_name, bool $enabled ): void {
		$states               = self::tool_states();
		$states[ $tool_name ] = $enabled;
		update_option( self::TOOL_STATES, $states, false );
	}
}
