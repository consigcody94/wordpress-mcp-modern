<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Admin\SettingsStore;
use WPMCP\Modern\Auth\JwtManager;
use WPMCP\Modern\Auth\OAuthProvider;

/**
 * OAuth 2.1 slice: dynamic client registration, PKCE code exchange (success,
 * bad verifier, code reuse), the disabled-by-default gate, and metadata shape.
 */
final class OAuthTest extends WP_UnitTestCase {

	private const REDIRECT = 'http://127.0.0.1:33418/callback';

	public function set_up(): void {
		parent::set_up();
		$settings                 = SettingsStore::all();
		$settings['enable_oauth'] = true;
		SettingsStore::update_settings( $settings );
	}

	public function tear_down(): void {
		delete_option( SettingsStore::SETTINGS );
		delete_option( OAuthProvider::CLIENTS_OPTION );
		parent::tear_down();
	}

	private function register_client(): string {
		$request = new \WP_REST_Request( 'POST', '/wpmcp/v1/oauth/register' );
		$request->set_body_params(
			array(
				'client_name'   => 'Test MCP Client',
				'redirect_uris' => array( self::REDIRECT ),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );
		return (string) $response->get_data()['client_id'];
	}

	/**
	 * @return \WP_REST_Response
	 */
	private function exchange( string $client_id, string $code, string $verifier ) {
		$request = new \WP_REST_Request( 'POST', '/wpmcp/v1/oauth/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::REDIRECT,
				'client_id'     => $client_id,
				'code_verifier' => $verifier,
			)
		);
		return rest_do_request( $request );
	}

	private static function challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	public function test_registration_validates_redirect_uris(): void {
		$request = new \WP_REST_Request( 'POST', '/wpmcp/v1/oauth/register' );
		$request->set_body_params( array( 'redirect_uris' => array( 'not-a-uri' ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_redirect_uri', $response->get_data()['error'] );
	}

	public function test_full_pkce_code_exchange_issues_a_valid_jwt(): void {
		$user_id   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$client_id = $this->register_client();
		$verifier  = str_repeat( 'v', 43 );
		$code      = OAuthProvider::issue_code( $client_id, self::REDIRECT, $user_id, self::challenge( $verifier ) );

		$response = $this->exchange( $client_id, $code, $verifier );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'Bearer', $data['token_type'] );
		$this->assertNotEmpty( $data['access_token'] );
		$this->assertSame( $user_id, JwtManager::validate( $data['access_token'] ), 'OAuth access tokens must be plugin JWTs tied to the consenting user' );
	}

	public function test_wrong_verifier_is_rejected(): void {
		$user_id   = self::factory()->user->create();
		$client_id = $this->register_client();
		$code      = OAuthProvider::issue_code( $client_id, self::REDIRECT, $user_id, self::challenge( str_repeat( 'v', 43 ) ) );

		$response = $this->exchange( $client_id, $code, str_repeat( 'x', 43 ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	public function test_codes_are_single_use(): void {
		$user_id   = self::factory()->user->create();
		$client_id = $this->register_client();
		$verifier  = str_repeat( 'v', 43 );
		$code      = OAuthProvider::issue_code( $client_id, self::REDIRECT, $user_id, self::challenge( $verifier ) );

		$this->assertSame( 200, $this->exchange( $client_id, $code, $verifier )->get_status() );

		$replay = $this->exchange( $client_id, $code, $verifier );
		$this->assertSame( 400, $replay->get_status() );
		$this->assertSame( 'invalid_grant', $replay->get_data()['error'] );
	}

	public function test_endpoints_are_gated_on_the_setting(): void {
		delete_option( SettingsStore::SETTINGS ); // Back to defaults: OAuth off.

		$request = new \WP_REST_Request( 'POST', '/wpmcp/v1/oauth/register' );
		$request->set_body_params( array( 'redirect_uris' => array( self::REDIRECT ) ) );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}

	public function test_metadata_documents_have_required_fields(): void {
		$as = OAuthProvider::authorization_server_metadata();
		foreach ( array( 'issuer', 'authorization_endpoint', 'token_endpoint', 'registration_endpoint' ) as $field ) {
			$this->assertNotEmpty( $as[ $field ], "AS metadata missing {$field}" );
		}
		$this->assertSame( array( 'S256' ), $as['code_challenge_methods_supported'] );

		$prm = OAuthProvider::protected_resource_metadata();
		$this->assertStringContainsString( 'wpmcp/mcp', $prm['resource'] );
		$this->assertNotEmpty( $prm['authorization_servers'] );
	}
}
