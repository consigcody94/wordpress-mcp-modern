<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * Comment capabilities over the core /wp/v2/comments REST routes: list/read,
 * create (replying = wp_add_comment with a parent), edit, moderate (status
 * changes), and delete. Moderation-grade tools require moderate_comments; the
 * REST layer enforces the finer-grained checks at call time.
 */
final class CommentsAbilities {

	public static function register(): void {
		foreach ( self::definitions() as $def ) {
			RestProxyAbility::register( $def );
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		$ns = AbilityRegistrar::NS;

		return array(
			array(
				'name'         => "$ns/wp-comments-search",
				'mcp_name'     => 'wp_comments_search',
				'label'        => 'Search comments',
				'description'  => 'Search and list comments with optional filters and pagination.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/comments',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string' ),
						'post'     => array( 'type' => 'integer', 'description' => 'Limit to comments on this post ID.' ),
						'parent'   => array( 'type' => 'integer', 'description' => 'Limit to direct replies to this comment ID.' ),
						'status'   => array( 'type' => 'string', 'description' => 'Comment status (approve, hold, spam, trash, all). Non-approved statuses require moderation capability.', 'default' => 'approve' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
					),
				),
			),
			array(
				'name'         => "$ns/wp-get-comment",
				'mcp_name'     => 'wp_get_comment',
				'label'        => 'Get comment',
				'description'  => 'Retrieve a single comment by ID.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/comments/{id}',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-add-comment",
				'mcp_name'     => 'wp_add_comment',
				'label'        => 'Add comment',
				'description'  => 'Add a comment to a post as the authenticated user; set parent to reply to an existing comment.',
				'type'         => 'create',
				'method'       => 'POST',
				'route'        => '/wp/v2/comments',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post'    => array( 'type' => 'integer', 'description' => 'The post to comment on.' ),
						'content' => array( 'type' => 'string' ),
						'parent'  => array( 'type' => 'integer', 'description' => 'Parent comment ID when replying.' ),
					),
					'required'   => array( 'post', 'content' ),
				),
			),
			array(
				'name'         => "$ns/wp-update-comment",
				'mcp_name'     => 'wp_update_comment',
				'label'        => 'Update comment',
				'description'  => 'Update a comment\'s content by ID.',
				'type'         => 'update',
				'method'       => 'POST',
				'route'        => '/wp/v2/comments/{id}',
				'capability'   => 'moderate_comments',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'content' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id', 'content' ),
				),
			),
			array(
				'name'         => "$ns/wp-moderate-comment",
				'mcp_name'     => 'wp_moderate_comment',
				'label'        => 'Moderate comment',
				'description'  => 'Change a comment\'s status: approve it, hold it for moderation, mark as spam, or trash it.',
				'type'         => 'update',
				'method'       => 'POST',
				'route'        => '/wp/v2/comments/{id}',
				'capability'   => 'moderate_comments',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'status' => array( 'type' => 'string', 'enum' => array( 'approved', 'hold', 'spam', 'trash' ) ),
					),
					'required'   => array( 'id', 'status' ),
				),
			),
			array(
				'name'         => "$ns/wp-delete-comment",
				'mcp_name'     => 'wp_delete_comment',
				'label'        => 'Delete comment',
				'description'  => 'Delete a comment by ID (use force to permanently delete instead of trashing).',
				'type'         => 'delete',
				'method'       => 'DELETE',
				'route'        => '/wp/v2/comments/{id}',
				'capability'   => 'moderate_comments',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'integer' ),
						'force' => array( 'type' => 'boolean', 'default' => true ),
					),
					'required'   => array( 'id' ),
				),
			),
		);
	}
}
