<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

use WPMCP\Modern\Plugin;

/**
 * Registers a WordPress Ability that mcp-adapter exposes as an MCP *resource*
 * (read-only contextual data addressed by URI), rather than a tool. The
 * `meta.mcp.uri` is required by mcp-adapter's resource builder.
 */
final class ResourceAbility {

	/**
	 * @param array $def {
	 *     @type string   $name        Full ability name.
	 *     @type string   $uri         Resource URI (scheme required), e.g. "wordpress://site-info".
	 *     @type string   $label       Title.
	 *     @type string   $description Description.
	 *     @type string   $capability  Capability checked before reading.
	 *     @type callable $execute     Returns the resource data (array/string).
	 * }
	 */
	public static function register( array $def ): void {
		$capability = $def['capability'];

		wp_register_ability(
			$def['name'],
			array(
				'label'               => $def['label'],
				'description'         => $def['description'],
				'category'            => Plugin::ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'permission_callback' => static function () use ( $capability ) {
					return current_user_can( $capability );
				},
				'execute_callback'    => $def['execute'],
				'meta'                => array(
					'mcp' => array(
						'type'     => 'resource',
						'uri'      => $def['uri'],
						'mimeType' => 'application/json',
					),
				),
			)
		);
	}
}
