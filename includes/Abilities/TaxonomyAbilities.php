<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * Category and tag (taxonomy term) capabilities, mirroring the legacy
 * wordpress-mcp term tools. List tools are plural (wp_list_categories), while
 * add/update/delete are singular (wp_add_category). Term deletion always forces
 * (terms cannot be trashed), so delete definitions pin `force=true`.
 */
final class TaxonomyAbilities {

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

		$taxonomies = array(
			array(
				'route'    => 'categories',
				'word'     => 'category',
				'singular' => 'category',
				'plural'   => 'categories',
			),
			array(
				'route'    => 'tags',
				'word'     => 'tag',
				'singular' => 'tag',
				'plural'   => 'tags',
			),
		);

		$defs = array();
		foreach ( $taxonomies as $tax ) {
			$route = $tax['route'];
			$word  = $tax['word'];
			$sing  = $tax['singular'];
			$plur  = $tax['plural'];

			$defs[] = array(
				'name'         => "$ns/wp-list-{$plur}",
				'mcp_name'     => "wp_list_{$plur}",
				'label'        => "List {$word}s",
				'description'  => "List {$word} terms with optional search and pagination.",
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => "/wp/v2/{$route}",
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'     => array( 'type' => 'string', 'description' => 'Search term.' ),
						'per_page'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
						'page'       => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'parent'     => array( 'type' => 'integer', 'description' => 'Limit to children of this term ID.' ),
						'orderby'    => array( 'type' => 'string', 'enum' => array( 'name', 'id', 'count', 'slug' ), 'default' => 'name' ),
						'order'      => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'asc' ),
						'hide_empty' => array( 'type' => 'boolean', 'description' => 'Hide terms not assigned to any posts.', 'default' => false ),
					),
				),
			);
			$defs[] = array(
				'name'         => "$ns/wp-add-{$sing}",
				'mcp_name'     => "wp_add_{$sing}",
				'label'        => "Add {$word}",
				'description'  => "Create a new {$word} term.",
				'type'         => 'create',
				'method'       => 'POST',
				'route'        => "/wp/v2/{$route}",
				'capability'   => 'manage_categories',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array( 'type' => 'string', 'description' => "The {$word} name." ),
						'description' => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer', 'description' => 'Parent term ID (hierarchical taxonomies only).' ),
					),
					'required'   => array( 'name' ),
				),
			);
			$defs[] = array(
				'name'         => "$ns/wp-update-{$sing}",
				'mcp_name'     => "wp_update_{$sing}",
				'label'        => "Update {$word}",
				'description'  => "Update an existing {$word} term by its ID.",
				'type'         => 'update',
				'method'       => 'PUT',
				'route'        => "/wp/v2/{$route}/{id}",
				'capability'   => 'manage_categories',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer', 'description' => "The {$word} ID to update." ),
						'name'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
			);
			$defs[] = array(
				'name'         => "$ns/wp-delete-{$sing}",
				'mcp_name'     => "wp_delete_{$sing}",
				'label'        => "Delete {$word}",
				'description'  => "Delete a {$word} term by its ID.",
				'type'         => 'delete',
				'method'       => 'DELETE',
				'route'        => "/wp/v2/{$route}/{id}",
				'capability'   => 'manage_categories',
				'extra_params' => array( 'force' => true ),
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'description' => "The {$word} ID to delete." ),
					),
					'required'   => array( 'id' ),
				),
			);
		}

		return $defs;
	}
}
