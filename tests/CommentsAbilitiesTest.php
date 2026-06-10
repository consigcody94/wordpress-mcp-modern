<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;

/**
 * Coverage for comments abilities: registration plus a full lifecycle
 * round-trip (add → reply → moderate → delete) through the REST proxy.
 */
final class CommentsAbilitiesTest extends WP_UnitTestCase {

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

	public function test_comments_abilities_are_registered(): void {
		$expected = array(
			'wordpress-mcp/wp-comments-search',
			'wordpress-mcp/wp-get-comment',
			'wordpress-mcp/wp-add-comment',
			'wordpress-mcp/wp-update-comment',
			'wordpress-mcp/wp-moderate-comment',
			'wordpress-mcp/wp-delete-comment',
		);
		foreach ( $expected as $name ) {
			$this->assertNotNull( $this->ability( $name ), "Ability not registered: {$name}" );
		}
	}

	public function test_comment_lifecycle_round_trip(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// Add.
		$added = $this->ability( 'wordpress-mcp/wp-add-comment' )->execute(
			array(
				'post'    => $post_id,
				'content' => 'First!',
			)
		);
		$this->assertArrayHasKey( 'id', $added, 'add should return the new comment' );
		$comment_id = (int) $added['id'];

		// Reply.
		$reply = $this->ability( 'wordpress-mcp/wp-add-comment' )->execute(
			array(
				'post'    => $post_id,
				'parent'  => $comment_id,
				'content' => 'Replying to first.',
			)
		);
		$this->assertSame( $comment_id, (int) $reply['parent'] );

		// Search finds them.
		$found = $this->ability( 'wordpress-mcp/wp-comments-search' )->execute( array( 'post' => $post_id ) );
		$this->assertCount( 2, $found );

		// Moderate: hold.
		$held = $this->ability( 'wordpress-mcp/wp-moderate-comment' )->execute(
			array(
				'id'     => $comment_id,
				'status' => 'hold',
			)
		);
		$this->assertSame( 'hold', $held['status'] );
		$this->assertSame( '0', get_comment( $comment_id )->comment_approved );

		// Update content.
		$updated = $this->ability( 'wordpress-mcp/wp-update-comment' )->execute(
			array(
				'id'      => $comment_id,
				'content' => 'Edited.',
			)
		);
		$this->assertStringContainsString( 'Edited.', $updated['content']['raw'] ?? $updated['content']['rendered'] );

		// Delete (force).
		$this->ability( 'wordpress-mcp/wp-delete-comment' )->execute(
			array(
				'id'    => $comment_id,
				'force' => true,
			)
		);
		$this->assertNull( get_comment( $comment_id ) );
	}

	public function test_moderation_tools_require_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertNotTrue( $this->ability( 'wordpress-mcp/wp-moderate-comment' )->check_permissions( array() ) );
		$this->assertNotTrue( $this->ability( 'wordpress-mcp/wp-delete-comment' )->check_permissions( array() ) );
	}
}
