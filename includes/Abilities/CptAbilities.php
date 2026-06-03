<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * Custom post type capabilities, mirroring the legacy wordpress-mcp CPT tools.
 * Because custom post types may not expose a fixed REST route, the CRUD tools
 * are native (WP_Query / wp_insert_post / wp_update_post / wp_delete_post). The
 * post-type listing reuses the core /wp/v2/types route via REST proxy.
 */
final class CptAbilities {

	public static function register(): void {
		foreach ( self::definitions() as $def ) {
			if ( 'rest' === ( $def['kind'] ?? 'native' ) ) {
				RestProxyAbility::register( $def );
			} else {
				NativeAbility::register( $def );
			}
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		$ns = AbilityRegistrar::NS;

		return array(
			array(
				'name'         => "$ns/wp-list-post-types",
				'mcp_name'     => 'wp_list_post_types',
				'kind'         => 'rest',
				'label'        => 'List post types',
				'description'  => 'List all registered post types and their REST/labels metadata.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/types',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
			),
			array(
				'name'         => "$ns/wp-cpt-search",
				'mcp_name'     => 'wp_cpt_search',
				'kind'         => 'native',
				'label'        => 'Search custom post type',
				'description'  => 'Search items of a given (custom) post type with pagination.',
				'type'         => 'read',
				'capability'   => 'edit_posts',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'description' => 'The post type slug.' ),
						'search'    => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string', 'default' => 'publish' ),
						'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
						'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'author'    => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_type' ),
				),
				'execute'      => static function ( array $input ) {
					$post_type = sanitize_key( $input['post_type'] ?? '' );
					if ( ! $post_type || ! post_type_exists( $post_type ) ) {
						return array(
							'error'   => 'invalid_post_type',
							'message' => "Unknown post type: {$post_type}",
						);
					}
					$query = new \WP_Query(
						array(
							'post_type'      => $post_type,
							's'              => (string) ( $input['search'] ?? '' ),
							'post_status'    => $input['status'] ?? 'publish',
							'posts_per_page' => min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) ),
							'paged'          => max( 1, (int) ( $input['page'] ?? 1 ) ),
							'author'         => ! empty( $input['author'] ) ? (int) $input['author'] : 0,
						)
					);
					$posts = array();
					foreach ( $query->posts as $post ) {
						$posts[] = array(
							'id'     => $post->ID,
							'title'  => get_the_title( $post ),
							'status' => $post->post_status,
							'type'   => $post->post_type,
							'date'   => $post->post_date,
							'link'   => get_permalink( $post ),
						);
					}
					return array(
						'total' => (int) $query->found_posts,
						'posts' => $posts,
					);
				},
			),
			array(
				'name'         => "$ns/wp-get-cpt",
				'mcp_name'     => 'wp_get_cpt',
				'kind'         => 'native',
				'label'        => 'Get custom post type item',
				'description'  => 'Retrieve a single item of a given post type by ID.',
				'type'         => 'read',
				'capability'   => 'edit_posts',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'description' => 'The post type slug.' ),
						'id'        => array( 'type' => 'integer', 'description' => 'The item ID.' ),
					),
					'required'   => array( 'post_type', 'id' ),
				),
				'execute'      => static function ( array $input ) {
					$post_type = sanitize_key( $input['post_type'] ?? '' );
					$post      = get_post( (int) ( $input['id'] ?? 0 ) );
					if ( ! $post || $post->post_type !== $post_type ) {
						return array(
							'error'   => 'not_found',
							'message' => 'Item not found for the given post type.',
						);
					}
					return array(
						'id'      => $post->ID,
						'title'   => $post->post_title,
						'content' => $post->post_content,
						'excerpt' => $post->post_excerpt,
						'status'  => $post->post_status,
						'type'    => $post->post_type,
						'date'    => $post->post_date,
						'link'    => get_permalink( $post ),
					);
				},
			),
			array(
				'name'         => "$ns/wp-add-cpt",
				'mcp_name'     => 'wp_add_cpt',
				'kind'         => 'native',
				'label'        => 'Add custom post type item',
				'description'  => 'Create a new item of a given post type.',
				'type'         => 'create',
				'capability'   => 'edit_posts',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'description' => 'The post type slug.' ),
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'excerpt'   => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string', 'default' => 'draft' ),
					),
					'required'   => array( 'post_type', 'title' ),
				),
				'execute'      => static function ( array $input ) {
					$post_type = sanitize_key( $input['post_type'] ?? '' );
					if ( ! $post_type || ! post_type_exists( $post_type ) ) {
						return array(
							'error'   => 'invalid_post_type',
							'message' => "Unknown post type: {$post_type}",
						);
					}
					$id = wp_insert_post(
						array(
							'post_type'    => $post_type,
							'post_title'   => (string) ( $input['title'] ?? '' ),
							'post_content' => (string) ( $input['content'] ?? '' ),
							'post_excerpt' => (string) ( $input['excerpt'] ?? '' ),
							'post_status'  => $input['status'] ?? 'draft',
						),
						true
					);
					if ( is_wp_error( $id ) ) {
						return array(
							'error'   => $id->get_error_code(),
							'message' => $id->get_error_message(),
						);
					}
					return array(
						'id'     => (int) $id,
						'type'   => $post_type,
						'status' => get_post_status( $id ),
					);
				},
			),
			array(
				'name'         => "$ns/wp-update-cpt",
				'mcp_name'     => 'wp_update_cpt',
				'kind'         => 'native',
				'label'        => 'Update custom post type item',
				'description'  => 'Update an existing item of a given post type by ID.',
				'type'         => 'update',
				'capability'   => 'edit_posts',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'description' => 'The post type slug.' ),
						'id'        => array( 'type' => 'integer', 'description' => 'The item ID.' ),
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'excerpt'   => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_type', 'id' ),
				),
				'execute'      => static function ( array $input ) {
					$post_type = sanitize_key( $input['post_type'] ?? '' );
					$post      = get_post( (int) ( $input['id'] ?? 0 ) );
					if ( ! $post || $post->post_type !== $post_type ) {
						return array(
							'error'   => 'not_found',
							'message' => 'Item not found for the given post type.',
						);
					}
					$data = array( 'ID' => $post->ID );
					foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status' ) as $in => $col ) {
						if ( isset( $input[ $in ] ) ) {
							$data[ $col ] = $input[ $in ];
						}
					}
					$result = wp_update_post( $data, true );
					if ( is_wp_error( $result ) ) {
						return array(
							'error'   => $result->get_error_code(),
							'message' => $result->get_error_message(),
						);
					}
					return array(
						'id'     => $post->ID,
						'status' => get_post_status( $post->ID ),
					);
				},
			),
			array(
				'name'         => "$ns/wp-delete-cpt",
				'mcp_name'     => 'wp_delete_cpt',
				'kind'         => 'native',
				'label'        => 'Delete custom post type item',
				'description'  => 'Delete an item of a given post type by ID.',
				'type'         => 'delete',
				'capability'   => 'edit_posts',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'description' => 'The post type slug.' ),
						'id'        => array( 'type' => 'integer', 'description' => 'The item ID.' ),
						'force'     => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'post_type', 'id' ),
				),
				'execute'      => static function ( array $input ) {
					$post_type = sanitize_key( $input['post_type'] ?? '' );
					$post      = get_post( (int) ( $input['id'] ?? 0 ) );
					if ( ! $post || $post->post_type !== $post_type ) {
						return array(
							'error'   => 'not_found',
							'message' => 'Item not found for the given post type.',
						);
					}
					$result = wp_delete_post( $post->ID, ! empty( $input['force'] ) );
					return array(
						'deleted' => (bool) $result,
						'id'      => $post->ID,
					);
				},
			),
		);
	}
}
