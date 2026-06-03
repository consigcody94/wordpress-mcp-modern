<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;
use WPMCP\Modern\Mcp\ServerProvider;

final class SmokeTest extends WP_UnitTestCase {

	public function test_plugin_classes_autoload(): void {
		$this->assertTrue( class_exists( \WPMCP\Modern\Plugin::class ) );
		$this->assertTrue( class_exists( ServerProvider::class ) );
	}

	public function test_abilities_api_available_in_test_env(): void {
		$this->assertTrue( function_exists( 'wp_register_ability' ) );
	}

	public function test_server_constants(): void {
		$this->assertSame( 'wpmcp-modern', ServerProvider::SERVER_ID );
		$this->assertSame( 'wpmcp', ServerProvider::NAMESPACE );
		$this->assertSame( 'mcp', ServerProvider::ROUTE );
	}
}
