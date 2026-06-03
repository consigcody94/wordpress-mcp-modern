<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

use WPMCP\Modern\Plugin;

/**
 * Registers a WordPress Ability backed by a native PHP callback (rather than a
 * REST proxy). Used where there is no single fixed REST route — e.g. arbitrary
 * custom post types or binary media handling.
 */
final class NativeAbility {

	/**
	 * @param array $def {
	 *     @type string   $name         Full ability name.
	 *     @type string   $label        Title.
	 *     @type string   $description  Description.
	 *     @type string   $type         read|create|update|delete|action (annotations).
	 *     @type string   $capability   Capability checked before execution.
	 *     @type array    $input_schema JSON-schema (object).
	 *     @type callable $execute      fn(array $input): mixed.
	 *     @type array    $annotations  Optional explicit MCP annotations.
	 * }
	 */
	public static function register( array $def ): void {
		$capability = $def['capability'];
		$execute    = $def['execute'];

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
				'execute_callback'    => static function ( $input ) use ( $execute ) {
					return $execute( (array) $input );
				},
				'meta'                => array(
					'annotations' => $def['annotations'] ?? self::annotations_for_type( $def['type'] ?? 'action' ),
				),
			)
		);
	}

	/**
	 * @return array<string,bool>
	 */
	private static function annotations_for_type( string $type ): array {
		switch ( $type ) {
			case 'read':
				return array( 'readonly' => true );
			case 'delete':
				return array( 'destructive' => true );
			default:
				return array( 'readonly' => false );
		}
	}
}
