<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * Post (and, in later slices, page/term) capabilities, expressed as REST-proxy
 * abilities mirroring the legacy wordpress-mcp content tools.
 *
 * Ability names are dash-cased (Abilities API requirement); the legacy
 * underscore tool names are carried in `mcp_name` and restored by the
 * `mcp_adapter_tool_name` filter (see AbilityRegistrar).
 */
final class ContentAbilities {

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
				'name'         => "$ns/wp-posts-search",
				'mcp_name'     => 'wp_posts_search',
				'label'        => 'Search posts',
				'description'  => 'Search and list WordPress posts with optional filters and pagination.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/posts',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array(
							'type'        => 'string',
							'description' => 'Search term to match against post content.',
						),
						'status'   => array(
							'type'        => 'string',
							'description' => 'Post status to filter by.',
							'enum'        => array( 'publish', 'future', 'draft', 'pending', 'private' ),
							'default'     => 'publish',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Number of posts to return (1-100).',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 10,
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Page of results to return.',
							'minimum'     => 1,
							'default'     => 1,
						),
						'author'   => array(
							'type'        => 'integer',
							'description' => 'Limit to a specific author user ID.',
						),
						'orderby'  => array(
							'type'        => 'string',
							'description' => 'Field to order results by.',
							'enum'        => array( 'date', 'title', 'id', 'modified', 'relevance' ),
							'default'     => 'date',
						),
						'order'    => array(
							'type'    => 'string',
							'enum'    => array( 'asc', 'desc' ),
							'default' => 'desc',
						),
					),
				),
			),
			array(
				'name'         => "$ns/wp-get-post",
				'mcp_name'     => 'wp_get_post',
				'label'        => 'Get post',
				'description'  => 'Retrieve a single WordPress post by its ID.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/posts/{id}',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'The post ID.',
						),
						'context' => array(
							'type'    => 'string',
							'enum'    => array( 'view', 'edit' ),
							'default' => 'view',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-add-post",
				'mcp_name'     => 'wp_add_post',
				'label'        => 'Add post',
				'description'  => 'Create a new WordPress post. Content uses the block (Gutenberg) format.',
				'type'         => 'create',
				'method'       => 'POST',
				'route'        => '/wp/v2/posts',
				'capability'   => 'edit_posts',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'   => array(
							'type'        => 'string',
							'description' => 'The post title.',
						),
						'content' => array(
							'type'        => 'string',
							'description' => 'The post content as block markup or HTML.',
						),
						'excerpt' => array(
							'type'        => 'string',
							'description' => 'Optional post excerpt.',
						),
						'status'  => array(
							'type'    => 'string',
							'enum'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
							'default' => 'draft',
						),
						'slug'    => array(
							'type'        => 'string',
							'description' => 'Optional URL slug.',
						),
					),
					'required'   => array( 'title' ),
				),
			),
			array(
				'name'         => "$ns/wp-update-post",
				'mcp_name'     => 'wp_update_post',
				'label'        => 'Update post',
				'description'  => 'Update an existing WordPress post by its ID.',
				'type'         => 'update',
				'method'       => 'PUT',
				'route'        => '/wp/v2/posts/{id}',
				'capability'   => 'edit_posts',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'The post ID to update.',
						),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'excerpt' => array( 'type' => 'string' ),
						'status'  => array(
							'type' => 'string',
							'enum' => array( 'publish', 'future', 'draft', 'pending', 'private' ),
						),
						'slug'    => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-delete-post",
				'mcp_name'     => 'wp_delete_post',
				'label'        => 'Delete post',
				'description'  => 'Delete a WordPress post by its ID (trashed unless force is true).',
				'type'         => 'delete',
				'method'       => 'DELETE',
				'route'        => '/wp/v2/posts/{id}',
				'capability'   => 'delete_posts',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => 'The post ID to delete.',
						),
						'force' => array(
							'type'        => 'boolean',
							'description' => 'Permanently delete instead of trashing.',
							'default'     => false,
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-pages-search",
				'mcp_name'     => 'wp_pages_search',
				'label'        => 'Search pages',
				'description'  => 'Search and list WordPress pages with optional filters and pagination.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/pages',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Search term.' ),
						'status'   => array( 'type' => 'string', 'enum' => array( 'publish', 'future', 'draft', 'pending', 'private' ), 'default' => 'publish' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'parent'   => array( 'type' => 'integer', 'description' => 'Limit to children of this page ID.' ),
						'orderby'  => array( 'type' => 'string', 'enum' => array( 'date', 'title', 'id', 'modified', 'menu_order' ), 'default' => 'menu_order' ),
						'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'asc' ),
					),
				),
			),
			array(
				'name'         => "$ns/wp-get-page",
				'mcp_name'     => 'wp_get_page',
				'label'        => 'Get page',
				'description'  => 'Retrieve a single WordPress page by its ID.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/pages/{id}',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer', 'description' => 'The page ID.' ),
						'context' => array( 'type' => 'string', 'enum' => array( 'view', 'edit' ), 'default' => 'view' ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-add-page",
				'mcp_name'     => 'wp_add_page',
				'label'        => 'Add page',
				'description'  => 'Create a new WordPress page.',
				'type'         => 'create',
				'method'       => 'POST',
				'route'        => '/wp/v2/pages',
				'capability'   => 'edit_pages',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'      => array( 'type' => 'string', 'description' => 'The page title.' ),
						'content'    => array( 'type' => 'string', 'description' => 'Page content as block markup or HTML.' ),
						'excerpt'    => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string', 'enum' => array( 'publish', 'future', 'draft', 'pending', 'private' ), 'default' => 'draft' ),
						'slug'       => array( 'type' => 'string' ),
						'parent'     => array( 'type' => 'integer', 'description' => 'Parent page ID.' ),
						'menu_order' => array( 'type' => 'integer', 'description' => 'Order among siblings.' ),
					),
					'required'   => array( 'title' ),
				),
			),
			array(
				'name'         => "$ns/wp-update-page",
				'mcp_name'     => 'wp_update_page',
				'label'        => 'Update page',
				'description'  => 'Update an existing WordPress page by its ID.',
				'type'         => 'update',
				'method'       => 'PUT',
				'route'        => '/wp/v2/pages/{id}',
				'capability'   => 'edit_pages',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer', 'description' => 'The page ID to update.' ),
						'title'      => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string' ),
						'excerpt'    => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string', 'enum' => array( 'publish', 'future', 'draft', 'pending', 'private' ) ),
						'slug'       => array( 'type' => 'string' ),
						'parent'     => array( 'type' => 'integer' ),
						'menu_order' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-delete-page",
				'mcp_name'     => 'wp_delete_page',
				'label'        => 'Delete page',
				'description'  => 'Delete a WordPress page by its ID (trashed unless force is true).',
				'type'         => 'delete',
				'method'       => 'DELETE',
				'route'        => '/wp/v2/pages/{id}',
				'capability'   => 'delete_pages',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'integer', 'description' => 'The page ID to delete.' ),
						'force' => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'id' ),
				),
			),
		);
	}
}
