<?php
declare(strict_types=1);

namespace WPMCP\Modern\Auth;

/**
 * REST routes for JWT issuance/management, mirroring the legacy jwt-auth/v1 API:
 *   POST /jwt-auth/v1/token   (public; current user or username/password)
 *   POST /jwt-auth/v1/revoke  (manage_options; body: jti)
 *   GET  /jwt-auth/v1/tokens  (manage_options)
 */
final class JwtRestRoutes {

	public const NS = 'jwt-auth/v1';

	public static function register(): void {
		register_rest_route(
			self::NS,
			'/token',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'issue_token' ),
			)
		);
		register_rest_route(
			self::NS,
			'/revoke',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'require_admin' ),
				'callback'            => array( self::class, 'revoke_token' ),
			)
		);
		register_rest_route(
			self::NS,
			'/tokens',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'require_admin' ),
				'callback'            => array( self::class, 'list_tokens' ),
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function require_admin() {
		return current_user_can( 'manage_options' )
			? true
			: new \WP_Error( 'wpmcp_forbidden', 'Administrator capability required.', array( 'status' => 403 ) );
	}

	public static function issue_token( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			$user = wp_authenticate(
				(string) $request->get_param( 'username' ),
				(string) $request->get_param( 'password' )
			);
			if ( is_wp_error( $user ) ) {
				return new \WP_Error( 'wpmcp_invalid_credentials', 'Invalid username or password.', array( 'status' => 403 ) );
			}
			$user_id = (int) $user->ID;
		}

		$expires_in = (int) ( $request->get_param( 'expires_in' ) ?: JwtManager::DEFAULT_EXP );
		if ( $expires_in < JwtManager::DEFAULT_EXP || $expires_in > JwtManager::max_expiration() ) {
			return new \WP_Error(
				'wpmcp_invalid_expiration',
				sprintf( 'expires_in must be between %d and %d seconds.', JwtManager::DEFAULT_EXP, JwtManager::max_expiration() ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( JwtManager::generate( $user_id, $expires_in ) );
	}

	public static function revoke_token( \WP_REST_Request $request ) {
		$jti = (string) $request->get_param( 'jti' );
		if ( '' === $jti ) {
			return new \WP_Error( 'wpmcp_missing_jti', 'A jti is required.', array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'revoked' => JwtManager::revoke( $jti ) ) );
	}

	public static function list_tokens() {
		return rest_ensure_response(
			array(
				'tokens'         => JwtManager::list_tokens(),
				'max_expiration' => JwtManager::max_expiration(),
			)
		);
	}
}
