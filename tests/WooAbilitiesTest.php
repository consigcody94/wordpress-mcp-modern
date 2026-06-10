<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Abilities\WooAbilities;

/**
 * WooCommerce abilities. WooCommerce is not loaded in the phpunit bootstrap, so
 * definitions() must be empty (the gate), while the ungated catalog() keeps the
 * full toolset testable: naming rules, required keys, and the expected tools.
 * Live verification of execution is done in wp-env over STDIO/HTTP.
 */
final class WooAbilitiesTest extends WP_UnitTestCase {

	public function test_definitions_are_gated_on_woocommerce(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->assertSame( WooAbilities::catalog(), WooAbilities::definitions() );
			return;
		}
		$this->assertSame(
			array(),
			WooAbilities::definitions(),
			'Woo abilities must be empty when WooCommerce is inactive.'
		);
	}

	public function test_catalog_contains_expected_tools(): void {
		$tools = array_column( WooAbilities::catalog(), 'mcp_name' );

		$expected = array(
			// Products.
			'wc_products_search',
			'wc_get_product',
			'wc_add_product',
			'wc_update_product',
			'wc_delete_product',
			// Brands (WooCommerce 9.4+ core taxonomy).
			'wc_list_product_brands',
			'wc_add_product_brand',
			'wc_update_product_brand',
			'wc_delete_product_brand',
			// Orders, including writes.
			'wc_orders_search',
			'wc_get_order',
			'wc_add_order',
			'wc_update_order',
			'wc_delete_order',
			// Reports.
			'wc_reports_sales',
		);
		foreach ( $expected as $tool ) {
			$this->assertContains( $tool, $tools, "Missing Woo tool: {$tool}" );
		}

		$this->assertCount( 28, $tools, 'Woo tool count drives the documented totals — update docs when changing it.' );
	}

	public function test_catalog_definitions_are_well_formed(): void {
		foreach ( WooAbilities::catalog() as $def ) {
			foreach ( array( 'name', 'mcp_name', 'label', 'description', 'type', 'method', 'route', 'capability', 'input_schema' ) as $key ) {
				$this->assertArrayHasKey( $key, $def, "Definition {$def['name']} missing key: {$key}" );
			}

			$slug = substr( $def['name'], strlen( 'wordpress-mcp/' ) );
			$this->assertMatchesRegularExpression(
				'/^[a-z0-9-]+$/',
				$slug,
				"Ability name must be lowercase dash-cased (no underscores): {$def['name']}"
			);
			$this->assertSame( 'manage_woocommerce', $def['capability'] );
		}
	}
}
