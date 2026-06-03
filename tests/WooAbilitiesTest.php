<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Abilities\WooAbilities;

/**
 * WooCommerce abilities. WooCommerce is not loaded in the phpunit bootstrap, so
 * here we verify the gate (empty definitions when WooCommerce is inactive). When
 * WooCommerce IS present, ability names must remain valid (dash-cased, no
 * underscores). Live verification of execution is done in wp-env over STDIO/HTTP.
 */
final class WooAbilitiesTest extends WP_UnitTestCase {

	public function test_definitions_are_gated_on_woocommerce(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->assertSame(
				array(),
				WooAbilities::definitions(),
				'Woo abilities must be empty when WooCommerce is inactive.'
			);
			return;
		}

		$names = array_column( WooAbilities::definitions(), 'name' );
		$this->assertNotEmpty( $names );
		$this->assertContains( 'wordpress-mcp/wc-products-search', $names );
		foreach ( $names as $name ) {
			$slug = substr( $name, strlen( 'wordpress-mcp/' ) );
			$this->assertMatchesRegularExpression(
				'/^[a-z0-9-]+$/',
				$slug,
				"Ability name must be lowercase dash-cased (no underscores): {$name}"
			);
		}
	}
}
