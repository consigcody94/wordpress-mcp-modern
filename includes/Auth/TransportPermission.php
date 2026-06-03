<?php
declare(strict_types=1);

namespace WPMCP\Modern\Auth;

/**
 * The MCP server's transport permission callback (create_server's 13th arg).
 *
 * Accepts either a Bearer JWT (validated + the WP user established, which the
 * mcp-adapter session layer requires) or an already-authenticated user (cookie,
 * or a WordPress Application Password validated by core). Fails closed otherwise.
 */
final class TransportPermission {

	/**
	 * @return true|\WP_Error
	 */
	public static function check( \WP_REST_Request $request ) {
		$auth = (string) $request->get_header( 'authorization' );

		if ( '' !== $auth && 0 === stripos( $auth, 'bearer ' ) ) {
			$token  = trim( substr( $auth, 7 ) );
			$result = JwtManager::validate( $token );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			// Establish identity so mcp-adapter sessions + per-ability permissions work.
			wp_set_current_user( (int) $result );
			return true;
		}

		// Fall back to an already-authenticated request (cookie / Application Password).
		if ( current_user_can( 'read' ) ) {
			return true;
		}

		return new \WP_Error(
			'wpmcp_unauthorized',
			'Authentication required: send a Bearer JWT or use a WordPress Application Password.',
			array( 'status' => 401 )
		);
	}
}
