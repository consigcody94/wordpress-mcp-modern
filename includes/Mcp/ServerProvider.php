<?php
declare(strict_types=1);

namespace WPMCP\Modern\Mcp;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WPMCP\Modern\Abilities\AbilityRegistrar;

/**
 * Registers the plugin's MCP server during mcp_adapter_init.
 */
final class ServerProvider {

	public const SERVER_ID = 'wpmcp-modern';
	public const NAMESPACE = 'wpmcp';
	public const ROUTE     = 'mcp';

	/**
	 * @param McpAdapter $adapter Passed by the mcp_adapter_init action.
	 */
	public static function create( McpAdapter $adapter ): void {
		$result = $adapter->create_server(
			self::SERVER_ID,                       // 1 server_id
			self::NAMESPACE,                       // 2 namespace  -> /wp-json/wpmcp/mcp
			self::ROUTE,                           // 3 route
			'WordPress MCP (Modern)',              // 4 name
			'WordPress capabilities exposed via the Abilities API.', // 5 description
			WPMCP_MODERN_VERSION,                  // 6 version
			array( HttpTransport::class ),         // 7 transports
			ErrorLogMcpErrorHandler::class,        // 8 error handler
			NullMcpObservabilityHandler::class,    // 9 observability handler
			AbilityRegistrar::tool_ability_names(),     // 10 tools
			AbilityRegistrar::resource_ability_names(), // 11 resources
			AbilityRegistrar::prompt_ability_names(),   // 12 prompts
			static function ( \WP_REST_Request $request ) { // 13 transport permission callback
				// Phase 2 placeholder: any authenticated user. Real JWT / App-Password
				// parity is added in Phase 10 (see modernization design section 5).
				unset( $request );
				return current_user_can( 'read' )
					? true
					: new \WP_Error( 'wpmcp_unauthorized', 'Authentication required.', array( 'status' => 401 ) );
			}
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'WPMCP Modern: create_server failed: ' . $result->get_error_message() );
		}
	}
}
