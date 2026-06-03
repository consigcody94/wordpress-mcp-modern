<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;

/**
 * Coverage for the native custom-post-type abilities (exercised against the
 * built-in "post" type).
 */
final class CptAbilitiesTest extends WP_UnitTestCase {

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

	public function test_cpt_abilities_are_registered(): void {
		$expected = array(
			'wordpress-mcp/wp-list-post-types',
			'wordpress-mcp/wp-cpt-search',
			'wordpress-mcp/wp-get-cpt',
			'wordpress-mcp/wp-add-cpt',
			'wordpress-mcp/wp-update-cpt',
			'wordpress-mcp/wp-delete-cpt',
		);
		foreach ( $expected as $name ) {
			$this->assertNotNull( $this->ability( $name ), "Ability not registered: {$name}" );
		}
	}

	public function test_invalid_post_type_is_reported(): void {
		$result = $this->ability( 'wordpress-mcp/wp-cpt-search' )->execute(
			array( 'post_type' => 'does_not_exist' )
		);
		$this->assertSame( 'invalid_post_type', $result['error'] ?? null );
	}

	public function test_native_cpt_crud_round_trip(): void {
		$created = $this->ability( 'wordpress-mcp/wp-add-cpt' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'CPT Native Item',
				'content'   => 'Body',
				'status'    => 'draft',
			)
		);
		$this->assertArrayHasKey( 'id', $created );
		$id = (int) $created['id'];
		$this->assertGreaterThan( 0, $id );

		$fetched = $this->ability( 'wordpress-mcp/wp-get-cpt' )->execute(
			array(
				'post_type' => 'post',
				'id'        => $id,
			)
		);
		$this->assertSame( 'CPT Native Item', $fetched['title'] );

		$updated = $this->ability( 'wordpress-mcp/wp-update-cpt' )->execute(
			array(
				'post_type' => 'post',
				'id'        => $id,
				'status'    => 'publish',
			)
		);
		$this->assertSame( 'publish', $updated['status'] );

		$deleted = $this->ability( 'wordpress-mcp/wp-delete-cpt' )->execute(
			array(
				'post_type' => 'post',
				'id'        => $id,
				'force'     => true,
			)
		);
		$this->assertTrue( $deleted['deleted'] );
	}
}
