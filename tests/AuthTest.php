<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Auth\JwtManager;
use WPMCP\Modern\Auth\TransportPermission;

/**
 * JWT manager + transport permission coverage.
 */
final class AuthTest extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	public function set_up(): void {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	public function test_generate_and_validate_round_trip(): void {
		$issued = JwtManager::generate( $this->user_id, 3600 );
		$this->assertArrayHasKey( 'token', $issued );
		$this->assertSame( 3600, $issued['expires_in'] );
		$this->assertSame( $this->user_id, JwtManager::validate( $issued['token'] ) );
	}

	public function test_expiry_is_clamped_to_bounds(): void {
		$short = JwtManager::generate( $this->user_id, 1 );          // below minimum
		$this->assertSame( JwtManager::DEFAULT_EXP, $short['expires_in'] );
		$long = JwtManager::generate( $this->user_id, PHP_INT_MAX ); // above maximum
		$this->assertSame( JwtManager::max_expiration(), $long['expires_in'] );
	}

	public function test_revoked_token_is_rejected(): void {
		$issued = JwtManager::generate( $this->user_id, 3600 );
		$this->assertTrue( JwtManager::revoke( $issued['jti'] ) );
		$this->assertWPError( JwtManager::validate( $issued['token'] ) );
	}

	public function test_tampered_token_is_rejected(): void {
		$this->assertWPError( JwtManager::validate( 'not.a.valid.jwt' ) );
	}

	public function test_secret_is_stable_and_non_empty(): void {
		$first = JwtManager::get_secret();
		$this->assertNotEmpty( $first );
		$this->assertSame( $first, JwtManager::get_secret() );
	}

	public function test_transport_permission_accepts_bearer_jwt(): void {
		$issued = JwtManager::generate( $this->user_id, 3600 );
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'POST', '/wpmcp/mcp' );
		$request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );

		$this->assertTrue( TransportPermission::check( $request ) );
		$this->assertSame( $this->user_id, get_current_user_id() );
	}

	public function test_transport_permission_rejects_anonymous(): void {
		wp_set_current_user( 0 );
		$this->assertWPError( TransportPermission::check( new \WP_REST_Request( 'POST', '/wpmcp/mcp' ) ) );
	}

	public function test_transport_permission_rejects_bad_bearer(): void {
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'POST', '/wpmcp/mcp' );
		$request->set_header( 'Authorization', 'Bearer garbage.token.value' );
		$this->assertWPError( TransportPermission::check( $request ) );
	}
}
