<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

use WPMCP\Modern\Plugin;

/**
 * Registers a WordPress Ability that proxies a core/plugin REST API route.
 *
 * The MCP input schema is supplied explicitly (so it is available the moment the
 * Abilities registry initialises, independent of when REST routes are added),
 * while execution dispatches through `rest_do_request()` so the real endpoint's
 * validation, sanitisation and permission logic run at call time. This mirrors
 * the legacy wordpress-mcp `rest_alias` behaviour as an ability.
 */
final class RestProxyAbility {

	/**
	 * Register one REST-proxy ability.
	 *
	 * @param array $def {
	 *     @type string   $name         Full ability name, e.g. "wordpress-mcp/wp_posts_search".
	 *     @type string   $label        Human-readable title.
	 *     @type string   $description  Tool description.
	 *     @type string   $type         read|create|update|delete|action (drives annotations).
	 *     @type string   $method       HTTP method to dispatch (GET|POST|PUT|PATCH|DELETE).
	 *     @type string   $route        REST route, may contain "{id}" placeholders.
	 *     @type string   $capability   Capability checked by the ability permission_callback.
	 *     @type array    $input_schema JSON-schema (object) for the tool input.
	 *     @type string[] $path_params  Input keys substituted into the route (default ["id"]).
	 *     @type array    $extra_params Fixed params always merged into the request.
	 *     @type array    $annotations  Optional explicit MCP annotations.
	 * }
	 */
	public static function register( array $def ): void {
		$method       = strtoupper( $def['method'] );
		$route        = $def['route'];
		$capability   = $def['capability'];
		$path_params  = $def['path_params'] ?? array( 'id' );
		$extra_params = $def['extra_params'] ?? array();

		wp_register_ability(
			$def['name'],
			array(
				'label'               => $def['label'],
				'description'         => $def['description'],
				'category'            => Plugin::ABILITY_CATEGORY,
				'input_schema'        => $def['input_schema'],
				'permission_callback' => static function () use ( $capability ) {
					return current_user_can( $capability );
				},
				'execute_callback'    => static function ( $input ) use ( $method, $route, $path_params, $extra_params ) {
					return self::dispatch( $method, $route, $path_params, $extra_params, (array) $input );
				},
				'meta'                => array(
					'annotations' => $def['annotations'] ?? self::annotations_for_type( $def['type'] ),
				),
			)
		);
	}

	/**
	 * Build and dispatch the REST request, returning plain data (or an error array).
	 *
	 * @param array<string,mixed> $input
	 * @return mixed
	 */
	private static function dispatch( string $method, string $route, array $path_params, array $extra_params, array $input ) {
		foreach ( $path_params as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$route = str_replace( '{' . $key . '}', rawurlencode( (string) $input[ $key ] ), $route );
				unset( $input[ $key ] );
			}
		}

		$params  = array_merge( $input, $extra_params );
		$request = new \WP_REST_Request( $method, $route );

		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body_params( $params );
		}

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();
			return array(
				'error'   => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error->get_error_data(),
			);
		}

		return $response->get_data();
	}

	/**
	 * Default MCP annotations derived from the tool type.
	 *
	 * @return array<string,bool>
	 */
	private static function annotations_for_type( string $type ): array {
		switch ( $type ) {
			case 'read':
				return array( 'readonly' => true );
			case 'delete':
				return array( 'destructive' => true );
			case 'create':
			case 'update':
			case 'action':
			default:
				return array( 'readonly' => false );
		}
	}
}
