<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

use WPMCP\Modern\Plugin;

/**
 * Registers a WordPress Ability that mcp-adapter exposes as an MCP *prompt*.
 *
 * On prompts/get the adapter calls the ability's execute() with the supplied
 * arguments; returning `{ messages: [...] }` yields a full MCP prompt result.
 */
final class PromptAbility {

	/**
	 * @param array $def {
	 *     @type string   $name        Full ability name.
	 *     @type string   $label       Title.
	 *     @type string   $description Description.
	 *     @type string   $capability  Capability checked before use.
	 *     @type array    $arguments   List of { name, description, required } argument specs.
	 *     @type callable $render      fn(array $args): string — renders the user-message text.
	 * }
	 */
	public static function register( array $def ): void {
		$capability = $def['capability'];
		$render     = $def['render'];
		$arguments  = $def['arguments'];

		$properties = array();
		$required   = array();
		foreach ( $arguments as $arg ) {
			$properties[ $arg['name'] ] = array(
				'type'        => 'string',
				'description' => $arg['description'] ?? '',
			);
			if ( ! empty( $arg['required'] ) ) {
				$required[] = $arg['name'];
			}
		}
		$input_schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( $required ) {
			$input_schema['required'] = $required;
		}

		wp_register_ability(
			$def['name'],
			array(
				'label'               => $def['label'],
				'description'         => $def['description'],
				'category'            => Plugin::ABILITY_CATEGORY,
				'input_schema'        => $input_schema,
				'permission_callback' => static function () use ( $capability ) {
					return current_user_can( $capability );
				},
				'execute_callback'    => static function ( $input ) use ( $render ) {
					$text = $render( (array) $input );
					return array(
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => array(
									'type' => 'text',
									'text' => $text,
								),
							),
						),
					);
				},
				'meta'                => array(
					'mcp' => array(
						'type'      => 'prompt',
						'arguments' => $arguments,
					),
				),
			)
		);
	}
}
