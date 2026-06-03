<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

use WPMCP\Modern\Admin\SettingsStore;

/**
 * Experimental generic REST-API CRUD tools, mirroring legacy wordpress-mcp.
 * Registered always (so they exist) but only *exposed* as tools when the
 * REST-CRUD "mode" is enabled (see AbilityRegistrar), where they replace the
 * curated toolset. Admin-only; writes still respect the create/update/delete
 * settings and each route's own permission check.
 */
final class RestCrudAbilities {

	private const EXCLUDE_EXACT      = array( '/', '/batch/v1' );
	private const EXCLUDE_SUBSTRINGS = array( 'oembed', 'autosaves', 'revisions', 'jwt-auth' );

	public static function register(): void {
		foreach ( self::definitions() as $def ) {
			NativeAbility::register( $def );
		}
	}

	/**
	 * @return string[]
	 */
	public static function names(): array {
		return array_column( self::definitions(), 'name' );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		$ns = AbilityRegistrar::NS;

		return array(
			array(
				'name'         => "$ns/list-api-functions",
				'mcp_name'     => 'list_api_functions',
				'label'        => 'List API functions',
				'description'  => 'Discover available WordPress REST API routes and the methods each supports.',
				'type'         => 'read',
				'capability'   => 'manage_options',
				'input_schema' => array( 'type' => 'object', 'properties' => array() ),
				'execute'      => static function () {
					return self::list_functions();
				},
			),
			array(
				'name'         => "$ns/get-function-details",
				'mcp_name'     => 'get_function_details',
				'label'        => 'Get function details',
				'description'  => 'Get the accepted parameters for a specific REST route + method.',
				'type'         => 'read',
				'capability'   => 'manage_options',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'route'  => array( 'type' => 'string', 'description' => 'REST route, e.g. /wp/v2/posts' ),
						'method' => array( 'type' => 'string', 'enum' => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), 'default' => 'GET' ),
					),
					'required'   => array( 'route' ),
				),
				'execute'      => static function ( array $input ) {
					return self::function_details( (string) ( $input['route'] ?? '' ), strtoupper( (string) ( $input['method'] ?? 'GET' ) ) );
				},
			),
			array(
				'name'         => "$ns/run-api-function",
				'mcp_name'     => 'run_api_function',
				'label'        => 'Run API function',
				'description'  => 'Execute any WordPress REST API route. Subject to your capabilities and the create/update/delete settings.',
				'type'         => 'action',
				'capability'   => 'manage_options',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'route'  => array( 'type' => 'string', 'description' => 'REST route to call, e.g. /wp/v2/posts/42' ),
						'method' => array( 'type' => 'string', 'enum' => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), 'default' => 'GET' ),
						'data'   => array( 'type' => 'object', 'description' => 'Body params for writes, or query params for GET.' ),
					),
					'required'   => array( 'route', 'method' ),
				),
				'execute'      => static function ( array $input ) {
					return self::run( (string) ( $input['route'] ?? '' ), strtoupper( (string) ( $input['method'] ?? 'GET' ) ), (array) ( $input['data'] ?? array() ) );
				},
			),
		);
	}

	private static function is_excluded( string $route ): bool {
		if ( in_array( $route, self::EXCLUDE_EXACT, true ) ) {
			return true;
		}
		foreach ( self::EXCLUDE_SUBSTRINGS as $needle ) {
			if ( false !== strpos( $route, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function list_functions(): array {
		$routes = rest_get_server()->get_routes();
		$out    = array();
		foreach ( $routes as $route => $handlers ) {
			if ( self::is_excluded( $route ) ) {
				continue;
			}
			$methods = array();
			foreach ( $handlers as $handler ) {
				foreach ( (array) ( $handler['methods'] ?? array() ) as $method => $enabled ) {
					if ( $enabled ) {
						$methods[ $method ] = true;
					}
				}
			}
			if ( $methods ) {
				$out[ $route ] = array_keys( $methods );
			}
		}
		return array(
			'count'  => count( $out ),
			'routes' => $out,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function function_details( string $route, string $method ): array {
		$routes = rest_get_server()->get_routes();
		if ( ! isset( $routes[ $route ] ) ) {
			return array( 'error' => 'route_not_found', 'message' => "Unknown route: {$route}" );
		}
		foreach ( $routes[ $route ] as $handler ) {
			$methods = (array) ( $handler['methods'] ?? array() );
			if ( ! empty( $methods[ $method ] ) ) {
				return array(
					'route'  => $route,
					'method' => $method,
					'args'   => $handler['args'] ?? array(),
				);
			}
		}
		return array( 'error' => 'method_not_supported', 'message' => "{$method} is not supported on {$route}" );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function run( string $route, string $method, array $data ): array {
		$type_map = array(
			'POST'   => 'create',
			'PUT'    => 'update',
			'PATCH'  => 'update',
			'DELETE' => 'delete',
		);
		if ( isset( $type_map[ $method ] ) && ! SettingsStore::is_type_enabled( $type_map[ $method ] ) ) {
			return array(
				'error'   => 'operation_disabled',
				'message' => ucfirst( $type_map[ $method ] ) . ' operations are disabled in settings.',
			);
		}
		if ( self::is_excluded( $route ) ) {
			return array( 'error' => 'route_excluded', 'message' => 'This route is not accessible via the generic runner.' );
		}

		$request = new \WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $data );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body_params( $data );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error = $response->as_error();
			return array( 'error' => $error->get_error_code(), 'message' => $error->get_error_message() );
		}
		return array(
			'status' => $response->get_status(),
			'data'   => $response->get_data(),
		);
	}
}
