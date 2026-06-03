<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * MCP prompts mirroring the legacy wordpress-mcp prompts. Unlike the legacy
 * implementation (which left a Handlebars {{#if}} block unevaluated), optional
 * argument handling is rendered in PHP.
 */
final class PromptAbilities {

	public static function register(): void {
		foreach ( self::definitions() as $def ) {
			PromptAbility::register( $def );
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
				'name'        => "$ns/get-site-info",
				'label'       => 'Get site info',
				'description' => 'Ask the assistant to summarize this WordPress site.',
				'capability'  => 'manage_options',
				'arguments'   => array(
					array(
						'name'        => 'info_type',
						'description' => 'Optional area to focus on (e.g. plugins, theme, users).',
						'required'    => false,
					),
				),
				'render'      => static function ( array $args ): string {
					$text = 'Provide a detailed overview of this WordPress site: name, URL, version, active plugins, current theme, and user roles.';
					if ( ! empty( $args['info_type'] ) ) {
						$text .= ' Focus especially on: ' . $args['info_type'] . '.';
					}
					return $text;
				},
			),
			array(
				'name'        => "$ns/analyze-sales",
				'label'       => 'Analyze sales',
				'description' => 'Ask the assistant to analyze WooCommerce sales for a period.',
				'capability'  => 'manage_options',
				'arguments'   => array(
					array(
						'name'        => 'time_span',
						'description' => 'The period to analyze (e.g. "last 30 days", "Q1 2026").',
						'required'    => true,
					),
				),
				'render'      => static function ( array $args ): string {
					$span = ! empty( $args['time_span'] ) ? $args['time_span'] : 'the requested period';
					return 'Analyze WooCommerce sales for ' . $span . '. Include total sales, average order value, top-selling products, and notable trends.';
				},
			),
		);
	}
}
