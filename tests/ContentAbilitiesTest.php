<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;

/**
 * Registration + execution coverage for the content (posts) abilities.
 */
final class ContentAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Find a registered ability by name (avoids depending on a singular getter).
	 */
	private function ability( string $name ) {
		foreach ( wp_get_abilities() as $ability ) {
			if ( $ability->get_name() === $name ) {
				return $ability;
			}
		}
		return null;
	}

	public function test_post_abilities_are_registered(): void {
		$expected = array(
			'wordpress-mcp/wp-posts-search',
			'wordpress-mcp/wp-get-post',
			'wordpress-mcp/wp-add-post',
			'wordpress-mcp/wp-update-post',
			'wordpress-mcp/wp-delete-post',
		);
		foreach ( $expected as $name ) {
			$this->assertNotNull( $this->ability( $name ), "Ability not registered: {$name}" );
		}
	}

	public function test_input_schema_root_is_object(): void {
		$schema = $this->ability( 'wordpress-mcp/wp-add-post' )->get_input_schema();
		$this->assertSame( 'object', $schema['type'] );
		$this->assertContains( 'title', $schema['required'] );
	}

	public function test_add_then_get_post_round_trips(): void {
		$created = $this->ability( 'wordpress-mcp/wp-add-post' )->execute(
			array(
				'title'   => 'Phase 3 Test',
				'content' => 'Body',
				'status'  => 'draft',
			)
		);
		$this->assertIsArray( $created );
		$this->assertArrayHasKey( 'id', $created, 'add-post should return the created post' );
		$id = (int) $created['id'];
		$this->assertGreaterThan( 0, $id );

		$fetched = $this->ability( 'wordpress-mcp/wp-get-post' )->execute( array( 'id' => $id ) );
		$this->assertIsArray( $fetched );
		$title = $fetched['title']['raw'] ?? ( $fetched['title']['rendered'] ?? '' );
		$this->assertSame( 'Phase 3 Test', $title );
	}

	public function test_search_finds_created_post(): void {
		$this->ability( 'wordpress-mcp/wp-add-post' )->execute(
			array(
				'title'  => 'Findable Draft',
				'status' => 'draft',
			)
		);

		$results = $this->ability( 'wordpress-mcp/wp-posts-search' )->execute(
			array(
				'status'   => 'draft',
				'per_page' => 20,
			)
		);
		$this->assertIsArray( $results );

		$titles = array_map(
			static function ( $post ) {
				return $post['title']['rendered'] ?? '';
			},
			$results
		);
		$this->assertContains( 'Findable Draft', $titles );
	}
}
