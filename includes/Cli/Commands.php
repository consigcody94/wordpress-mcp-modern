<?php
declare(strict_types=1);

namespace WPMCP\Modern\Cli;

use WPMCP\Modern\Abilities\AbilityRegistrar;
use WPMCP\Modern\Admin\SettingsStore;
use WPMCP\Modern\Auth\JwtManager;

/**
 * Manage the WordPress MCP server from the command line.
 *
 * Registered as `wp wpmcp` when WP-CLI is present — scriptable provisioning of
 * tokens, settings, and per-tool toggles.
 */
final class Commands {

	/**
	 * Manage JWT tokens.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of: generate, list, revoke.
	 *
	 * [<jti>]
	 * : Token ID (for revoke).
	 *
	 * [--user=<user>]
	 * : User ID or login to issue the token for (generate; defaults to the current user).
	 *
	 * [--expires-in=<seconds>]
	 * : Token lifetime in seconds (generate; default 3600, max 30 days).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpmcp token generate --user=admin --expires-in=86400
	 *     wp wpmcp token list
	 *     wp wpmcp token revoke 123e4567-e89b-12d3-a456-426614174000
	 *
	 * @param string[] $args
	 * @param array<string,string> $assoc_args
	 */
	public function token( array $args, array $assoc_args ): void {
		$action = $args[0] ?? 'list';

		switch ( $action ) {
			case 'generate':
				$user = $assoc_args['user'] ?? null;
				if ( null !== $user ) {
					$user_obj = is_numeric( $user ) ? get_user_by( 'id', (int) $user ) : get_user_by( 'login', (string) $user );
					if ( ! $user_obj ) {
						\WP_CLI::error( "User not found: {$user}" );
					}
					$user_id = (int) $user_obj->ID;
				} else {
					$user_id = get_current_user_id();
					if ( ! $user_id ) {
						\WP_CLI::error( 'No user context. Pass --user=<id|login>.' );
					}
				}
				$issued = JwtManager::generate( $user_id, (int) ( $assoc_args['expires-in'] ?? JwtManager::DEFAULT_EXP ) );
				\WP_CLI::log( $issued['token'] );
				\WP_CLI::success( sprintf( 'Token for user %d, expires %s (jti %s).', $issued['user_id'], gmdate( 'Y-m-d H:i', $issued['expires_at'] ), $issued['jti'] ) );
				break;

			case 'list':
				\WP_CLI\Utils\format_items( 'table', JwtManager::list_tokens(), array( 'jti', 'user_id', 'issued_at', 'expires_at', 'revoked' ) );
				break;

			case 'revoke':
				$jti = $args[1] ?? '';
				if ( '' === $jti ) {
					\WP_CLI::error( 'Usage: wp wpmcp token revoke <jti>' );
				}
				JwtManager::revoke( $jti )
					? \WP_CLI::success( "Revoked {$jti}." )
					: \WP_CLI::error( "Unknown jti: {$jti}" );
				break;

			default:
				\WP_CLI::error( "Unknown action: {$action} (expected generate|list|revoke)." );
		}
	}

	/**
	 * Read or update plugin settings.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of: list, set.
	 *
	 * [<key>]
	 * : Setting key (for set), e.g. enabled, enable_delete_tools, enable_audit_log.
	 *
	 * [<value>]
	 * : 1/0, true/false, on/off (for set).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpmcp settings list
	 *     wp wpmcp settings set enable_delete_tools 0
	 *
	 * @param string[] $args
	 */
	public function settings( array $args ): void {
		$action = $args[0] ?? 'list';

		if ( 'list' === $action ) {
			$rows = array();
			foreach ( SettingsStore::all() as $key => $value ) {
				$rows[] = array(
					'setting' => $key,
					'value'   => $value ? '1' : '0',
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'setting', 'value' ) );
			return;
		}

		if ( 'set' !== $action ) {
			\WP_CLI::error( "Unknown action: {$action} (expected list|set)." );
		}

		$key = $args[1] ?? '';
		if ( ! array_key_exists( $key, SettingsStore::defaults() ) ) {
			\WP_CLI::error( 'Unknown setting: ' . $key . '. Valid keys: ' . implode( ', ', array_keys( SettingsStore::defaults() ) ) );
		}
		$value             = filter_var( $args[2] ?? '', FILTER_VALIDATE_BOOLEAN );
		$settings          = SettingsStore::all();
		$settings[ $key ] = $value;
		SettingsStore::update_settings( $settings );
		\WP_CLI::success( sprintf( '%s = %s', $key, $value ? '1' : '0' ) );
	}

	/**
	 * List or toggle individual MCP tools.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of: list, enable, disable.
	 *
	 * [<tool>]
	 * : Tool name (for enable/disable), e.g. wp_posts_search.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpmcp tools list
	 *     wp wpmcp tools disable wp_delete_post
	 *
	 * @param string[] $args
	 */
	public function tools( array $args ): void {
		$action = $args[0] ?? 'list';

		if ( 'list' === $action ) {
			$rows = array();
			foreach ( AbilityRegistrar::tool_catalog() as $entry ) {
				$rows[] = array(
					'tool'    => $entry['tool'],
					'type'    => $entry['type'],
					'enabled' => SettingsStore::is_tool_enabled( $entry['tool'] ) ? '1' : '0',
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'tool', 'type', 'enabled' ) );
			return;
		}

		if ( ! in_array( $action, array( 'enable', 'disable' ), true ) ) {
			\WP_CLI::error( "Unknown action: {$action} (expected list|enable|disable)." );
		}

		$tool  = $args[1] ?? '';
		$known = array_column( AbilityRegistrar::tool_catalog(), 'tool' );
		if ( ! in_array( $tool, $known, true ) ) {
			\WP_CLI::error( "Unknown tool: {$tool} (see `wp wpmcp tools list`)." );
		}
		SettingsStore::set_tool_state( $tool, 'enable' === $action );
		\WP_CLI::success( sprintf( '%s %sd.', $tool, $action ) );
	}
}
