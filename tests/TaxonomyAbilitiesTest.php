<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;

/**
 * Registration + execution coverage for category/tag abilities.
 */
final class TaxonomyAbilitiesTest extends WP_UnitTestCase {

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

	public function test_term_abilities_are_registered(): void {
		$expected = array(
			'wordpress-mcp/wp-list-categories',
			'wordpress-mcp/wp-add-category',
			'wordpress-mcp/wp-update-category',
			'wordpress-mcp/wp-delete-category',
			'wordpress-mcp/wp-list-tags',
			'wordpress-mcp/wp-add-tag',
			'wordpress-mcp/wp-update-tag',
			'wordpress-mcp/wp-delete-tag',
		);
		foreach ( $expected as $name ) {
			$this->assertNotNull( $this->ability( $name ), "Ability not registered: {$name}" );
		}
	}

	public function test_add_then_list_category_round_trips(): void {
		$created = $this->ability( 'wordpress-mcp/wp-add-category' )->execute(
			array( 'name' => 'Announcements' )
		);
		$this->assertIsArray( $created );
		$this->assertArrayHasKey( 'id', $created );

		$listed = $this->ability( 'wordpress-mcp/wp-list-categories' )->execute(
			array( 'search' => 'Announce' )
		);
		$this->assertIsArray( $listed );
		$names = array_map(
			static function ( $term ) {
				return $term['name'] ?? '';
			},
			$listed
		);
		$this->assertContains( 'Announcements', $names );
	}

	public function test_delete_tag_forces_removal(): void {
		$created = $this->ability( 'wordpress-mcp/wp-add-tag' )->execute( array( 'name' => 'Ephemeral' ) );
		$id      = (int) $created['id'];

		$deleted = $this->ability( 'wordpress-mcp/wp-delete-tag' )->execute( array( 'id' => $id ) );
		$this->assertIsArray( $deleted );
		// WP returns { deleted: true, previous: {...} } on a forced term delete.
		$this->assertTrue( ! empty( $deleted['deleted'] ) || isset( $deleted['previous'] ) );
	}
}
