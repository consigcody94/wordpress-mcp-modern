<?php
declare(strict_types=1);

namespace WPMCP\Modern\Observability;

use WPMCP\Modern\Admin\SettingsStore;

/**
 * Tool-call observability, built on mcp-adapter's filters:
 *
 * - Audit log: `mcp_adapter_tool_call_result` records every tool call (time,
 *   user, tool, success) into a ring-buffer option. Off by default.
 * - Rate limiting: `mcp_adapter_pre_tool_call` short-circuits execution with a
 *   429 WP_Error once a user exceeds the per-minute budget. Off by default;
 *   budget filterable via `wpmcp_rate_limit_per_minute` (default 60).
 */
final class AuditLog {

	public const OPTION = 'wpmcp_audit_log';

	public const DEFAULT_RATE_LIMIT = 60;

	public static function register(): void {
		add_filter( 'mcp_adapter_pre_tool_call', array( self::class, 'enforce_rate_limit' ), 10, 2 );
		add_filter( 'mcp_adapter_tool_call_result', array( self::class, 'record' ), 10, 3 );
	}

	/**
	 * Filter: mcp_adapter_pre_tool_call. Returning WP_Error short-circuits the call.
	 *
	 * @param array|\WP_Error $args      Tool arguments (or an earlier filter's error).
	 * @param string          $tool_name Tool being called.
	 * @return array|\WP_Error
	 */
	public static function enforce_rate_limit( $args, $tool_name ) {
		if ( is_wp_error( $args ) || ! SettingsStore::get( 'enable_rate_limiting' ) ) {
			return $args;
		}

		$limit = (int) apply_filters( 'wpmcp_rate_limit_per_minute', self::DEFAULT_RATE_LIMIT );
		if ( $limit <= 0 ) {
			return $args;
		}

		$key   = sprintf( 'wpmcp_rl_%d_%d', get_current_user_id(), (int) floor( time() / MINUTE_IN_SECONDS ) );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );

		if ( $count > $limit ) {
			return new \WP_Error(
				'wpmcp_rate_limited',
				sprintf( 'Rate limit exceeded (%d tool calls per minute). Try again shortly.', $limit ),
				array( 'status' => 429 )
			);
		}

		return $args;
	}

	/**
	 * Filter: mcp_adapter_tool_call_result. Records and returns the result untouched.
	 *
	 * @param mixed  $result    Raw execution result (may be WP_Error or error array).
	 * @param array  $args      Tool arguments.
	 * @param string $tool_name Tool that was called.
	 * @return mixed
	 */
	public static function record( $result, $args, $tool_name ) {
		if ( ! SettingsStore::get( 'enable_audit_log' ) ) {
			return $result;
		}

		$entries   = self::entries();
		$entries[] = array(
			'time'    => time(),
			'user_id' => get_current_user_id(),
			'tool'    => (string) $tool_name,
			'ok'      => ! is_wp_error( $result ) && ! ( is_array( $result ) && isset( $result['error'] ) ),
		);

		$max = (int) apply_filters( 'wpmcp_audit_log_max_entries', 100 );
		if ( count( $entries ) > $max ) {
			$entries = array_slice( $entries, -$max );
		}
		update_option( self::OPTION, $entries, false );

		return $result;
	}

	/**
	 * Recorded entries, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries(): array {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
