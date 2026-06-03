<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;

/**
 * Registration + execution coverage for user abilities.
 */
final class UsersAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function ability( string $name ) {
		foreach ( wp_get_abilities() as $ability ) {
			if ( $ability->get_name() === $name ) {
				return $ability;
			}
		}
		return null;
	}

	public function test_user_abilities_are_registered(): void {
		$expected = array(
			'wordpress-mcp/wp-users-search',
			'wordpress-mcp/wp-get-user',
			'wordpress-mcp/wp-add-user',
			'wordpress-mcp/wp-update-user',
			'wordpress-mcp/wp-delete-user',
			'wordpress-mcp/wp-get-current-user',
			'wordpress-mcp/wp-update-current-user',
		);
		foreach ( $expected as $name ) {
			$this->assertNotNull( $this->ability( $name ), "Ability not registered: {$name}" );
		}
	}

	public function test_get_current_user_returns_authenticated_user(): void {
		$me = $this->ability( 'wordpress-mcp/wp-get-current-user' )->execute( array() );
		$this->assertIsArray( $me );
		$this->assertSame( get_current_user_id(), (int) $me['id'] );
	}

	public function test_add_then_get_user_round_trips(): void {
		$created = $this->ability( 'wordpress-mcp/wp-add-user' )->execute(
			array(
				'username' => 'mcp_user',
				'email'    => 'mcp_user@example.com',
				'password' => 'Str0ng-P@ssw0rd!',
				'roles'    => array( 'subscriber' ),
			)
		);
		$this->assertIsArray( $created );
		$this->assertArrayHasKey( 'id', $created, 'add-user should return the created user' );
		$id = (int) $created['id'];

		$fetched = $this->ability( 'wordpress-mcp/wp-get-user' )->execute( array( 'id' => $id ) );
		$this->assertIsArray( $fetched );
		$this->assertSame( 'mcp_user', $fetched['slug'] ?? $fetched['username'] ?? '' );
	}
}
