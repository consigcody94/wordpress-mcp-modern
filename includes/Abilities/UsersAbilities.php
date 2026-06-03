<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * User capabilities, mirroring the legacy wordpress-mcp user tools. User
 * deletion cannot trash, so it pins `force=true` and accepts a `reassign`
 * target (required by the REST endpoint).
 */
final class UsersAbilities {

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
				'name'         => "$ns/wp-users-search",
				'mcp_name'     => 'wp_users_search',
				'label'        => 'Search users',
				'description'  => 'Search and list WordPress users with optional filters and pagination.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/users',
				'capability'   => 'list_users',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Search term (matches name, login, email).' ),
						'roles'    => array( 'type' => 'string', 'description' => 'Comma-separated role slugs to filter by.' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'orderby'  => array( 'type' => 'string', 'enum' => array( 'id', 'name', 'registered_date', 'email', 'url' ), 'default' => 'name' ),
						'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'asc' ),
						'context'  => array( 'type' => 'string', 'enum' => array( 'view', 'edit' ), 'default' => 'view' ),
					),
				),
			),
			array(
				'name'         => "$ns/wp-get-user",
				'mcp_name'     => 'wp_get_user',
				'label'        => 'Get user',
				'description'  => 'Retrieve a single WordPress user by ID.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/users/{id}',
				'capability'   => 'list_users',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer', 'description' => 'The user ID.' ),
						'context' => array( 'type' => 'string', 'enum' => array( 'view', 'edit' ), 'default' => 'view' ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-add-user",
				'mcp_name'     => 'wp_add_user',
				'label'        => 'Add user',
				'description'  => 'Create a new WordPress user.',
				'type'         => 'create',
				'method'       => 'POST',
				'route'        => '/wp/v2/users',
				'capability'   => 'create_users',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'username'   => array( 'type' => 'string', 'description' => 'Login name for the user.' ),
						'email'      => array( 'type' => 'string', 'description' => 'Email address for the user.' ),
						'password'   => array( 'type' => 'string', 'description' => 'Password for the user.' ),
						'name'       => array( 'type' => 'string', 'description' => 'Display name.' ),
						'first_name' => array( 'type' => 'string' ),
						'last_name'  => array( 'type' => 'string' ),
						'url'        => array( 'type' => 'string' ),
						'roles'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Role slugs to assign (e.g. ["subscriber"]).',
						),
					),
					'required'   => array( 'username', 'email', 'password' ),
				),
			),
			array(
				'name'         => "$ns/wp-update-user",
				'mcp_name'     => 'wp_update_user',
				'label'        => 'Update user',
				'description'  => 'Update an existing WordPress user by ID.',
				'type'         => 'update',
				'method'       => 'PUT',
				'route'        => '/wp/v2/users/{id}',
				'capability'   => 'edit_users',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer', 'description' => 'The user ID to update.' ),
						'email'      => array( 'type' => 'string' ),
						'name'       => array( 'type' => 'string' ),
						'first_name' => array( 'type' => 'string' ),
						'last_name'  => array( 'type' => 'string' ),
						'url'        => array( 'type' => 'string' ),
						'password'   => array( 'type' => 'string' ),
						'roles'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-delete-user",
				'mcp_name'     => 'wp_delete_user',
				'label'        => 'Delete user',
				'description'  => 'Delete a WordPress user by ID. Content is reassigned to the given user.',
				'type'         => 'delete',
				'method'       => 'DELETE',
				'route'        => '/wp/v2/users/{id}',
				'capability'   => 'delete_users',
				'extra_params' => array( 'force' => true ),
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer', 'description' => 'The user ID to delete.' ),
						'reassign' => array( 'type' => 'integer', 'description' => 'User ID to reassign the deleted user\'s content to.' ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-get-current-user",
				'mcp_name'     => 'wp_get_current_user',
				'label'        => 'Get current user',
				'description'  => 'Retrieve the currently authenticated user.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/users/me',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'context' => array( 'type' => 'string', 'enum' => array( 'view', 'edit' ), 'default' => 'view' ),
					),
				),
			),
			array(
				'name'         => "$ns/wp-update-current-user",
				'mcp_name'     => 'wp_update_current_user',
				'label'        => 'Update current user',
				'description'  => 'Update the currently authenticated user.',
				'type'         => 'update',
				'method'       => 'PUT',
				'route'        => '/wp/v2/users/me',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'       => array( 'type' => 'string' ),
						'first_name' => array( 'type' => 'string' ),
						'last_name'  => array( 'type' => 'string' ),
						'email'      => array( 'type' => 'string' ),
						'url'        => array( 'type' => 'string' ),
						'password'   => array( 'type' => 'string' ),
					),
				),
			),
		);
	}
}
