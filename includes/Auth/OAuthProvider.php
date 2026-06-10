<?php
declare(strict_types=1);

namespace WPMCP\Modern\Auth;

use WPMCP\Modern\Admin\SettingsStore;
use WPMCP\Modern\Mcp\ServerProvider;

/**
 * Experimental OAuth 2.1 authorization for MCP clients (opt-in via the
 * enable_oauth setting). Implements the slice of the MCP authorization spec
 * that clients actually exercise:
 *
 * - RFC 8414 authorization-server metadata + RFC 9728 protected-resource
 *   metadata at /.well-known/* (served by intercepting parse_request — no
 *   rewrite rules or flushes needed).
 * - RFC 7591 dynamic client registration (public clients, exact redirect URIs).
 * - Authorization-code grant with mandatory PKCE (S256 only) and a WordPress
 *   consent screen at /?wpmcp_oauth=authorize.
 * - The token endpoint exchanges codes for the plugin's existing revocable
 *   JWTs, so OAuth-issued access tokens flow through the same validation and
 *   revocation machinery as manually issued ones.
 */
final class OAuthProvider {

	public const CLIENTS_OPTION = 'wpmcp_oauth_clients';
	public const CODE_TTL       = 600; // 10 minutes.
	private const MAX_CLIENTS   = 100;

	public static function register(): void {
		add_action( 'parse_request', array( self::class, 'maybe_intercept' ), 0 );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( self::class, 'advertise_resource_metadata' ), 10, 3 );
	}

	private static function enabled(): bool {
		return SettingsStore::get( 'enable_oauth' );
	}

	// ------------------------------------------------------------------
	// Discovery (.well-known) + the authorization endpoint.
	// ------------------------------------------------------------------

	/**
	 * parse_request interceptor: serves the well-known metadata documents and
	 * the authorization endpoint without requiring rewrite rules.
	 */
	public static function maybe_intercept(): void {
		if ( ! self::enabled() ) {
			return;
		}

		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

		if ( 0 === strpos( $path, '/.well-known/oauth-authorization-server' ) ) {
			self::send_json( self::authorization_server_metadata() );
		}
		if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
			self::send_json( self::protected_resource_metadata() );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth entry point; nonce checked on the consent POST.
		if ( 'authorize' === ( $_GET['wpmcp_oauth'] ?? '' ) ) {
			self::handle_authorize();
		}
	}

	/**
	 * RFC 8414 document.
	 *
	 * @return array<string,mixed>
	 */
	public static function authorization_server_metadata(): array {
		return array(
			'issuer'                                => home_url( '/' ),
			'authorization_endpoint'                => home_url( '/?wpmcp_oauth=authorize' ),
			'token_endpoint'                        => rest_url( 'wpmcp/v1/oauth/token' ),
			'registration_endpoint'                 => rest_url( 'wpmcp/v1/oauth/register' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( 'wordpress' ),
		);
	}

	/**
	 * RFC 9728 document.
	 *
	 * @return array<string,mixed>
	 */
	public static function protected_resource_metadata(): array {
		return array(
			'resource'                 => rest_url( ServerProvider::NAMESPACE . '/' . ServerProvider::ROUTE ),
			'authorization_servers'    => array( home_url( '/' ) ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * The authorization endpoint: validates the request, ensures a logged-in
	 * user, and renders/handles the consent screen.
	 */
	private static function handle_authorize(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth request parameters; the approval POST is nonce-checked below.
		$client_id    = sanitize_text_field( wp_unslash( $_GET['client_id'] ?? '' ) );
		$redirect_uri = esc_url_raw( wp_unslash( $_GET['redirect_uri'] ?? '' ) );
		$state        = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$challenge    = sanitize_text_field( wp_unslash( $_GET['code_challenge'] ?? '' ) );
		$method       = sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ?? '' ) );
		$response     = sanitize_text_field( wp_unslash( $_GET['response_type'] ?? '' ) );
		// phpcs:enable

		$client = self::clients()[ $client_id ] ?? null;
		// Never redirect to an unvalidated URI: hard-fail instead.
		if ( ! $client || ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			wp_die( esc_html__( 'Unknown OAuth client or redirect URI.', 'wordpress-mcp-modern' ), '', array( 'response' => 400 ) );
		}
		if ( 'code' !== $response || '' === $challenge || 'S256' !== $method ) {
			self::redirect_error( $redirect_uri, $state, 'invalid_request' );
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( 'wpmcp_oauth_consent' );
			$decision = sanitize_text_field( wp_unslash( $_POST['wpmcp_decision'] ?? '' ) );
			if ( 'approve' === $decision ) {
				$code     = self::issue_code( $client_id, $redirect_uri, get_current_user_id(), $challenge );
				$location = add_query_arg(
					array_filter(
						array(
							'code'  => $code,
							'state' => $state,
						)
					),
					$redirect_uri
				);
				wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external client redirect URI, validated against the registered list.
				exit;
			}
			self::redirect_error( $redirect_uri, $state, 'access_denied' );
		}

		self::render_consent_screen( $client );
	}

	/**
	 * Mint a single-use authorization code bound to client + redirect + PKCE
	 * challenge. Public so the test suite can drive the code flow directly.
	 */
	public static function issue_code( string $client_id, string $redirect_uri, int $user_id, string $code_challenge ): string {
		$code = wp_generate_password( 40, false, false );
		set_transient(
			'wpmcp_oauth_code_' . md5( $code ),
			array(
				'client_id'      => $client_id,
				'redirect_uri'   => $redirect_uri,
				'user_id'        => $user_id,
				'code_challenge' => $code_challenge,
			),
			self::CODE_TTL
		);
		return $code;
	}

	private static function redirect_error( string $redirect_uri, string $state, string $error ): void {
		wp_redirect( add_query_arg( array_filter( array( 'error' => $error, 'state' => $state ) ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * @param array<string,mixed> $client
	 */
	private static function render_consent_screen( array $client ): void {
		$name = (string) ( $client['client_name'] ?? 'An MCP client' );
		$user = wp_get_current_user();

		status_header( 200 );
		nocache_headers();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php esc_html_e( 'Authorize MCP access', 'wordpress-mcp-modern' ); ?></title>
			<style>
				body { font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f0f1; display: flex; justify-content: center; padding-top: 8vh; }
				.card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 2em; max-width: 26em; }
				button { font-size: 14px; padding: .5em 1.5em; border-radius: 3px; cursor: pointer; }
				.approve { background: #2271b1; border: 1px solid #2271b1; color: #fff; margin-right: .5em; }
				.deny { background: #f6f7f7; border: 1px solid #c3c4c7; }
			</style>
		</head>
		<body>
			<div class="card">
				<h1><?php esc_html_e( 'Authorize MCP access', 'wordpress-mcp-modern' ); ?></h1>
				<p>
					<?php
					printf(
						/* translators: 1: client name, 2: user login. */
						esc_html__( '%1$s wants to access this WordPress site through MCP as %2$s. It will be able to do anything your account can do, within the tools enabled in Settings → WordPress MCP.', 'wordpress-mcp-modern' ),
						'<strong>' . esc_html( $name ) . '</strong>',
						'<strong>' . esc_html( $user->user_login ) . '</strong>'
					);
					?>
				</p>
				<form method="post">
					<?php wp_nonce_field( 'wpmcp_oauth_consent' ); ?>
					<button class="approve" type="submit" name="wpmcp_decision" value="approve"><?php esc_html_e( 'Approve', 'wordpress-mcp-modern' ); ?></button>
					<button class="deny" type="submit" name="wpmcp_decision" value="deny"><?php esc_html_e( 'Deny', 'wordpress-mcp-modern' ); ?></button>
				</form>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	// ------------------------------------------------------------------
	// REST: dynamic client registration + token endpoint.
	// ------------------------------------------------------------------

	public static function register_routes(): void {
		register_rest_route(
			'wpmcp/v1',
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // RFC 7591 open registration; gated on the enable_oauth setting in the callback.
				'callback'            => array( self::class, 'handle_register' ),
			)
		);
		register_rest_route(
			'wpmcp/v1',
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'handle_token' ),
			)
		);
	}

	public static function handle_register( \WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'wpmcp_oauth_disabled', 'OAuth is not enabled on this site.', array( 'status' => 404 ) );
		}

		$redirect_uris = $request->get_param( 'redirect_uris' );
		if ( ! is_array( $redirect_uris ) || array() === $redirect_uris ) {
			return self::oauth_error( 'invalid_client_metadata', 'redirect_uris must be a non-empty array.' );
		}
		foreach ( $redirect_uris as $uri ) {
			$scheme = is_string( $uri ) ? (string) wp_parse_url( $uri, PHP_URL_SCHEME ) : '';
			if ( ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
				return self::oauth_error( 'invalid_redirect_uri', 'redirect_uris must be absolute http(s) URIs.' );
			}
		}

		$clients = self::clients();
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			return self::oauth_error( 'invalid_client_metadata', 'Client registry is full; an administrator must prune wpmcp_oauth_clients.' );
		}

		$client_id             = wp_generate_uuid4();
		$client                = array(
			'client_name'   => sanitize_text_field( (string) $request->get_param( 'client_name' ) ),
			'redirect_uris' => array_map( 'esc_url_raw', $redirect_uris ),
			'created'       => time(),
		);
		$clients[ $client_id ] = $client;
		update_option( self::CLIENTS_OPTION, $clients, false );

		return new \WP_REST_Response(
			array(
				'client_id'                  => $client_id,
				'client_name'                => $client['client_name'],
				'redirect_uris'              => $client['redirect_uris'],
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code' ),
				'response_types'             => array( 'code' ),
			),
			201
		);
	}

	public static function handle_token( \WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'wpmcp_oauth_disabled', 'OAuth is not enabled on this site.', array( 'status' => 404 ) );
		}

		if ( 'authorization_code' !== $request->get_param( 'grant_type' ) ) {
			return self::oauth_error( 'unsupported_grant_type', 'Only authorization_code is supported.' );
		}

		$code     = (string) $request->get_param( 'code' );
		$verifier = (string) $request->get_param( 'code_verifier' );
		$key      = 'wpmcp_oauth_code_' . md5( $code );
		$grant    = get_transient( $key );

		if ( '' === $code || ! is_array( $grant ) ) {
			return self::oauth_error( 'invalid_grant', 'Unknown or expired authorization code.' );
		}
		delete_transient( $key ); // Single use, even on failure below.

		if ( $grant['client_id'] !== (string) $request->get_param( 'client_id' )
			|| $grant['redirect_uri'] !== (string) $request->get_param( 'redirect_uri' ) ) {
			return self::oauth_error( 'invalid_grant', 'client_id or redirect_uri does not match the authorization request.' );
		}

		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		if ( '' === $verifier || ! hash_equals( (string) $grant['code_challenge'], $computed ) ) {
			return self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
		}

		$expires_in = (int) apply_filters( 'wpmcp_oauth_token_expiration', DAY_IN_SECONDS );
		$issued     = JwtManager::generate( (int) $grant['user_id'], $expires_in );

		return rest_ensure_response(
			array(
				'access_token' => $issued['token'],
				'token_type'   => 'Bearer',
				'expires_in'   => $issued['expires_in'],
				'scope'        => 'wordpress',
			)
		);
	}

	/**
	 * RFC 6749-shaped error body ({"error": ..., "error_description": ...}).
	 */
	private static function oauth_error( string $error, string $description ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			400
		);
	}

	/**
	 * rest_post_dispatch filter: point 401s on the MCP route at the protected
	 * resource metadata, per the MCP authorization spec.
	 *
	 * @param \WP_REST_Response $result
	 * @param \WP_REST_Server   $server
	 * @param \WP_REST_Request  $request
	 * @return \WP_REST_Response
	 */
	public static function advertise_resource_metadata( $result, $server, $request ) {
		if ( self::enabled()
			&& $result instanceof \WP_REST_Response
			&& 401 === $result->get_status()
			&& 0 === strpos( (string) $request->get_route(), '/' . ServerProvider::NAMESPACE . '/' ) ) {
			$result->header(
				'WWW-Authenticate',
				'Bearer resource_metadata="' . esc_url_raw( home_url( '/.well-known/oauth-protected-resource' ) ) . '"'
			);
		}
		return $result;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function clients(): array {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $clients : array();
	}

	private static function send_json( array $payload ): void {
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $payload );
		exit;
	}
}
