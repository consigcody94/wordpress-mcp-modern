<?php
declare(strict_types=1);

namespace WPMCP\Modern\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Issues, validates, and revokes HS256 JWTs for MCP access.
 *
 * Tokens are stateful: each is recorded in a server-side registry so they can be
 * revoked independently of expiry (matching legacy wordpress-mcp behaviour).
 *
 * Secret precedence: the WPMCP_JWT_SECRET_KEY constant (honoured here — the
 * legacy plugin documented but never read it), else an auto-generated option.
 */
final class JwtManager {

	public const SECRET_OPTION   = 'wpmcp_jwt_secret_key';
	public const REGISTRY_OPTION = 'wpmcp_jwt_token_registry';
	public const ALGORITHM       = 'HS256';
	public const DEFAULT_EXP     = 3600;      // 1 hour.
	public const MAX_EXP         = 2592000;   // 30 days.

	public static function get_secret(): string {
		if ( defined( 'WPMCP_JWT_SECRET_KEY' ) && constant( 'WPMCP_JWT_SECRET_KEY' ) ) {
			return (string) constant( 'WPMCP_JWT_SECRET_KEY' );
		}
		$secret = get_option( self::SECRET_OPTION );
		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return (string) $secret;
	}

	public static function max_expiration(): int {
		return (int) apply_filters( 'wpmcp_jwt_max_expiration_time', self::MAX_EXP );
	}

	/**
	 * Issue a token for a user.
	 *
	 * @return array{token:string,user_id:int,expires_in:int,expires_at:int,jti:string}
	 */
	public static function generate( int $user_id, int $expires_in ): array {
		$expires_in = $expires_in > 0 ? $expires_in : self::DEFAULT_EXP;
		$expires_in = max( self::DEFAULT_EXP, min( $expires_in, self::max_expiration() ) );

		$now = time();
		$jti = wp_generate_uuid4();

		$payload = array(
			'iss'     => get_bloginfo( 'url' ),
			'iat'     => $now,
			'exp'     => $now + $expires_in,
			'user_id' => $user_id,
			'jti'     => $jti,
		);

		$token = JWT::encode( $payload, self::get_secret(), self::ALGORITHM );

		$registry         = self::registry();
		$registry[ $jti ] = array(
			'user_id'    => $user_id,
			'issued_at'  => $now,
			'expires_at' => $now + $expires_in,
			'revoked'    => false,
		);
		update_option( self::REGISTRY_OPTION, $registry, false );

		return array(
			'token'      => $token,
			'user_id'    => $user_id,
			'expires_in' => $expires_in,
			'expires_at' => $now + $expires_in,
			'jti'        => $jti,
		);
	}

	/**
	 * Validate a token. Returns the user ID on success, or WP_Error.
	 *
	 * @return int|\WP_Error
	 */
	public static function validate( string $token ) {
		try {
			$decoded = JWT::decode( $token, new Key( self::get_secret(), self::ALGORITHM ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wpmcp_invalid_token', 'Invalid or expired token.', array( 'status' => 401 ) );
		}

		$jti      = isset( $decoded->jti ) ? (string) $decoded->jti : '';
		$registry = self::registry();
		if ( '' === $jti || empty( $registry[ $jti ] ) || ! empty( $registry[ $jti ]['revoked'] ) ) {
			return new \WP_Error( 'wpmcp_revoked_token', 'Token has been revoked or is unknown.', array( 'status' => 401 ) );
		}

		$user_id = isset( $decoded->user_id ) ? (int) $decoded->user_id : 0;
		if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
			return new \WP_Error( 'wpmcp_invalid_user', 'Token user no longer exists.', array( 'status' => 401 ) );
		}

		return $user_id;
	}

	public static function revoke( string $jti ): bool {
		$registry = self::registry();
		if ( isset( $registry[ $jti ] ) ) {
			$registry[ $jti ]['revoked'] = true;
			update_option( self::REGISTRY_OPTION, $registry, false );
			return true;
		}
		return false;
	}

	/**
	 * List non-expired tokens (lazily pruning expired ones).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_tokens(): array {
		$registry = self::registry();
		$now      = time();
		$changed  = false;
		$out      = array();

		foreach ( $registry as $jti => $entry ) {
			if ( (int) ( $entry['expires_at'] ?? 0 ) < $now ) {
				unset( $registry[ $jti ] );
				$changed = true;
				continue;
			}
			$out[] = array(
				'jti'        => $jti,
				'user_id'    => (int) ( $entry['user_id'] ?? 0 ),
				'issued_at'  => (int) ( $entry['issued_at'] ?? 0 ),
				'expires_at' => (int) ( $entry['expires_at'] ?? 0 ),
				'revoked'    => ! empty( $entry['revoked'] ),
			);
		}

		if ( $changed ) {
			update_option( self::REGISTRY_OPTION, $registry, false );
		}

		return $out;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function registry(): array {
		$registry = get_option( self::REGISTRY_OPTION, array() );
		return is_array( $registry ) ? $registry : array();
	}
}
