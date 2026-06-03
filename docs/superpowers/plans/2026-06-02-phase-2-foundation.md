# Phase 2 — Foundation & Live Empty Server: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the new plugin skeleton on `wordpress/mcp-adapter` so that a live, empty MCP server answers `initialize` and `tools/list` in wp-env — and resolve the environment unknowns (Abilities API availability, `php-mcp-schema` resolution, `create_server` arity) that gate Phases 3+.

**Architecture:** A WordPress plugin that loads mcp-adapter as a Composer library, boots it via `McpAdapter::instance()`, registers an ability category, and creates one custom MCP server (`wpmcp` namespace, `mcp` route) with an empty ability list and a logged-in permission callback. Verification is done against a Dockerized WordPress (`@wordpress/env`) using the official `composer` Docker image (no local PHP).

**Tech Stack:** PHP 7.4+, WordPress 6.9+ (Abilities API), `wordpress/mcp-adapter` v0.5.0 (local path repo), `firebase/php-jwt` (for later phases), `@wordpress/env` + Docker, wp-phpunit.

**Conventions used throughout:**
- Project root: `C:\Users\Cody\WPMCP\wordpress-mcp-modern` (the deliverable repo; already `git init`-ed).
- The mcp-adapter clone is a sibling: `C:\Users\Cody\WPMCP\mcp-adapter`.
- Run Composer without local PHP via Docker, from the project root:
  `docker run --rm -v "/c/Users/Cody/WPMCP/wordpress-mcp-modern":/app -v "/c/Users/Cody/WPMCP/mcp-adapter":/mcp-adapter -w /app composer:2 <composer-args>`
  (mcp-adapter is mounted at `/mcp-adapter` so the path repo resolves inside the container; see Task 1).
- Run WordPress/CLI via `npx @wordpress/env ...` and `npx wp-env run cli wp ...` from the project root.

---

### Task 1: Composer manifest + dependency resolution (resolves open Qs #3, #4)

**Files:**
- Create: `composer.json`

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "wpmcp/wordpress-mcp-modern",
    "description": "Modern WordPress MCP plugin built on the Abilities API + mcp-adapter.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "path", "url": "/mcp-adapter", "options": { "symlink": false } }
    ],
    "require": {
        "php": ">=7.4",
        "wordpress/mcp-adapter": "*",
        "firebase/php-jwt": "^6.11"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "yoast/phpunit-polyfills": "^4.0",
        "wp-phpunit/wp-phpunit": "^6.5",
        "php-stubs/wordpress-stubs": "^6.9"
    },
    "autoload": {
        "psr-4": { "WPMCP\\Modern\\": "includes/" }
    },
    "autoload-dev": {
        "psr-4": { "WPMCP\\Modern\\Tests\\": "tests/" }
    },
    "config": {
        "allow-plugins": { "dealerdirect/phpcodesniffer-composer-installer": true }
    }
}
```

> The `repositories` path URL is `/mcp-adapter` — the **in-container** mount point (see the Docker conventions above), not a Windows path. This pins the exact v0.5.0 we analyzed and sidesteps Packagist/network drift.

- [ ] **Step 2: Install dependencies (Docker composer)**

Run:
```
docker run --rm \
  -v "/c/Users/Cody/WPMCP/wordpress-mcp-modern":/app \
  -v "/c/Users/Cody/WPMCP/mcp-adapter":/mcp-adapter \
  -w /app composer:2 install --no-interaction
```
Expected: resolves and writes `vendor/`. mcp-adapter installed from the path repo; `wordpress/php-mcp-schema` pulled from Packagist (dev stability).

- [ ] **Step 3: Verify the critical packages resolved**

Run:
```
ls vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php \
   vendor/wordpress/php-mcp-schema 2>&1
```
Expected: both paths exist.

**BRANCH — if `wordpress/php-mcp-schema` did NOT resolve from Packagist:** clone it and add a second path repo, then re-install.
```
git clone --depth 1 https://github.com/WordPress/php-mcp-schema.git /c/Users/Cody/WPMCP/php-mcp-schema
```
Add to `composer.json` `repositories`:
```json
{ "type": "path", "url": "/php-mcp-schema", "options": { "symlink": false } }
```
Add `-v "/c/Users/Cody/WPMCP/php-mcp-schema":/php-mcp-schema` to the docker `-v` flags and re-run Step 2.

**BRANCH — if mcp-adapter path constraint `*` fails to match:** change the require to `"wordpress/mcp-adapter": "@dev"` and re-run.

- [ ] **Step 4: Record findings** in `docs/superpowers/PHASE-2-FINDINGS.md` (create it): resolved versions of mcp-adapter and php-mcp-schema (from `composer show`), and whether either fallback branch was needed. (Answers open Qs #3/#4.)

- [ ] **Step 5: Commit**
```
git add composer.json composer.lock docs/superpowers/PHASE-2-FINDINGS.md
git commit -m "build: composer manifest + pin mcp-adapter via path repo"
```

---

### Task 2: Plugin bootstrap, Plugin orchestrator, empty MCP server

**Files:**
- Create: `wordpress-mcp-modern.php`
- Create: `includes/Plugin.php`
- Create: `includes/Mcp/ServerProvider.php`

- [ ] **Step 1: Write the plugin bootstrap `wordpress-mcp-modern.php`**

```php
<?php
/**
 * Plugin Name:       WordPress MCP (Modern)
 * Description:       Exposes WordPress capabilities to AI agents via the Abilities API and mcp-adapter.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       wordpress-mcp-modern
 *
 * @package WPMCP\Modern
 */

declare(strict_types=1);

namespace WPMCP\Modern;

defined( 'ABSPATH' ) || exit;

define( 'WPMCP_MODERN_VERSION', '0.1.0' );
define( 'WPMCP_MODERN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPMCP_MODERN_URL', plugin_dir_url( __FILE__ ) );

$wpmcp_modern_autoload = WPMCP_MODERN_PATH . 'vendor/autoload.php';
if ( ! is_readable( $wpmcp_modern_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'WordPress MCP (Modern): run composer install in the plugin directory.', 'wordpress-mcp-modern' )
			);
		}
	);
	return;
}
require_once $wpmcp_modern_autoload;

add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'WordPress MCP (Modern) requires the Abilities API (WordPress 6.9+).', 'wordpress-mcp-modern' )
					);
				}
			);
			return;
		}
		if ( ! class_exists( \WP\MCP\Core\McpAdapter::class ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'WordPress MCP (Modern): mcp-adapter library not found (composer install).', 'wordpress-mcp-modern' )
					);
				}
			);
			return;
		}

		// mcp-adapter is loaded as a Composer library, so its own plugin bootstrap
		// (mcp-adapter.php) never runs — boot the adapter ourselves.
		\WP\MCP\Core\McpAdapter::instance();

		Plugin::instance();
	}
);
```

- [ ] **Step 2: Write `includes/Plugin.php`**

```php
<?php
declare(strict_types=1);

namespace WPMCP\Modern;

use WPMCP\Modern\Mcp\ServerProvider;

/**
 * Top-level orchestrator: registers the ability category and wires the MCP server.
 */
final class Plugin {

	public const ABILITY_CATEGORY = 'wordpress-mcp';

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'mcp_adapter_init', array( ServerProvider::class, 'create' ) );
	}

	public function register_category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				self::ABILITY_CATEGORY,
				array(
					'label'       => __( 'WordPress MCP', 'wordpress-mcp-modern' ),
					'description' => __( 'Capabilities exposed by the WordPress MCP (Modern) plugin.', 'wordpress-mcp-modern' ),
				)
			);
		}
	}
}
```

- [ ] **Step 3: Write `includes/Mcp/ServerProvider.php`**

```php
<?php
declare(strict_types=1);

namespace WPMCP\Modern\Mcp;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;

/**
 * Registers the plugin's MCP server during mcp_adapter_init.
 */
final class ServerProvider {

	public const SERVER_ID = 'wpmcp-modern';
	public const NAMESPACE = 'wpmcp';
	public const ROUTE     = 'mcp';

	/**
	 * @param McpAdapter $adapter Passed by the mcp_adapter_init action.
	 */
	public static function create( McpAdapter $adapter ): void {
		$result = $adapter->create_server(
			self::SERVER_ID,                       // 1 server_id
			self::NAMESPACE,                       // 2 namespace  → /wp-json/wpmcp/mcp
			self::ROUTE,                           // 3 route
			'WordPress MCP (Modern)',              // 4 name
			'WordPress capabilities exposed via the Abilities API.', // 5 description
			WPMCP_MODERN_VERSION,                  // 6 version
			array( HttpTransport::class ),         // 7 transports
			ErrorLogMcpErrorHandler::class,        // 8 error handler
			NullMcpObservabilityHandler::class,    // 9 observability handler
			array(),                               // 10 tools (empty for Phase 2)
			array(),                               // 11 resources
			array(),                               // 12 prompts
			static function ( \WP_REST_Request $request ) { // 13 transport permission callback
				// Phase 2 placeholder: any authenticated user. Real JWT/App-Password
				// parity is added in Phase 10 (see modernization design §5).
				return current_user_can( 'read' )
					? true
					: new \WP_Error( 'wpmcp_unauthorized', 'Authentication required.', array( 'status' => 401 ) );
			}
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'WPMCP Modern: create_server failed: ' . $result->get_error_message() );
		}
	}
}
```

- [ ] **Step 4: Re-dump the autoloader** (new classes added)
```
docker run --rm -v "/c/Users/Cody/WPMCP/wordpress-mcp-modern":/app -v "/c/Users/Cody/WPMCP/mcp-adapter":/mcp-adapter -w /app composer:2 dump-autoload
```
Expected: "Generated autoload files".

- [ ] **Step 5: Commit**
```
git add wordpress-mcp-modern.php includes/
git commit -m "feat: plugin bootstrap + empty MCP server on /wp-json/wpmcp/mcp"
```

---

### Task 3: wp-env config + bring up WordPress + verify Abilities API (resolves open Q #1)

**Files:**
- Create: `.wp-env.json`

- [ ] **Step 1: Write `.wp-env.json`**

```json
{
	"$schema": "https://schemas.wp.org/trunk/wp-env.json",
	"core": null,
	"plugins": [ "." ],
	"config": {
		"WP_DEBUG": true,
		"WP_DEBUG_LOG": true,
		"WP_DEBUG_DISPLAY": false
	}
}
```
(`core: null` = latest stable WordPress. As of 2026-06 this should be ≥ 6.9.)

- [ ] **Step 2: Start the environment**

Run: `npx @wordpress/env start`
Expected: ends with "WordPress development site started at http://localhost:8888". (Docker daemon is already up.)

- [ ] **Step 3: Verify WordPress version is ≥ 6.9**

Run: `npx wp-env run cli wp core version`
Expected: `6.9` or higher.

**BRANCH — if `< 6.9`:** pin core in `.wp-env.json` to a known-good build, e.g. `"core": "WordPress/WordPress#6.9"`, then `npx @wordpress/env start --update`.

- [ ] **Step 4: Verify the Abilities API is present**

Run: `npx wp-env run cli wp eval "var_export( function_exists('wp_register_ability') );"`
Expected: `true`.

**BRANCH — if `false`:** the Abilities API is not in core for this build. Add it as a plugin in `.wp-env.json`:
```json
"plugins": [ ".", "WordPress/abilities-api" ]
```
then `npx @wordpress/env start --update` and re-run Step 4. Record the outcome (core-bundled vs. plugin) in `PHASE-2-FINDINGS.md`.

- [ ] **Step 5: Activate the plugin and confirm no fatal / no dependency notice**

Run:
```
npx wp-env run cli wp plugin activate wordpress-mcp-modern
npx wp-env run cli wp plugin list --status=active --field=name
```
Expected: `wordpress-mcp-modern` listed; the command exits 0 (a fatal would surface here).

- [ ] **Step 6: Confirm the server registered**

Run: `npx wp-env run cli wp mcp-adapter list`
Expected: a row for `wpmcp-modern` (Name "WordPress MCP (Modern)", 0 tools/resources/prompts), alongside the default server.

- [ ] **Step 7: Commit**
```
git add .wp-env.json docs/superpowers/PHASE-2-FINDINGS.md
git commit -m "build: wp-env config; verified Abilities API + server registration"
```

---

### Task 4: End-to-end verification of the live empty server (Phase 2 Definition of Done)

**Files:** none (verification only; capture transcript in `PHASE-2-FINDINGS.md`).

- [ ] **Step 1: Create an Application Password for HTTP auth**

Run: `npx wp-env run cli wp user application-password create admin wpmcp-test --porcelain`
Expected: a 24-char password printed (no spaces). Save it as `APPPW` for the next steps.

- [ ] **Step 2: `initialize` over HTTP and capture the session id**

Run (replace `APPPW`):
```
curl -s -i -u "admin:APPPW" -X POST http://localhost:8888/wp-json/wpmcp/mcp \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Mcp-Protocol-Version: 2025-06-18" \
  -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"2025-06-18\",\"capabilities\":{},\"clientInfo\":{\"name\":\"curl\",\"version\":\"0\"}}}"
```
Expected: HTTP 200; body is JSON-RPC with `result.serverInfo.name == "WordPress MCP (Modern)"` and a `result.protocolVersion`; response headers include `Mcp-Session-Id: <uuid>`. Save the uuid as `SID`.

**BRANCH — 401:** the permission callback denied. Confirm the app password is correct and that `admin` has `read`. **BRANCH — 400 "invalid Accept" / protocol error:** note the exact required headers from the response and adjust (the adapter baseline is MCP 2025-11-25; try `Mcp-Protocol-Version: 2025-11-25`).

- [ ] **Step 3: `tools/list` over HTTP using the session id**

Run (replace `APPPW` and `SID`):
```
curl -s -u "admin:APPPW" -X POST http://localhost:8888/wp-json/wpmcp/mcp \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Mcp-Protocol-Version: 2025-06-18" \
  -H "Mcp-Session-Id: SID" \
  -d "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/list\",\"params\":{}}"
```
Expected: HTTP 200; JSON-RPC `result.tools` is an empty array `[]` (no abilities yet). **This is the Phase 2 success criterion.**

- [ ] **Step 4: Smoke-test STDIO transport (best effort)**

Run:
```
echo "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\",\"params\":{}}" | npx wp-env run cli wp mcp-adapter serve --server=wpmcp-modern --user=admin
```
Expected: a single JSON-RPC line with `result.tools == []`. (If stdin piping through `wp-env run` misbehaves, note it and rely on the HTTP DoD; STDIO is re-verified in later phases.)

- [ ] **Step 5: Record the full transcript** (initialize + tools/list responses, headers) in `PHASE-2-FINDINGS.md`, including the actual `protocolVersion` echoed and whether `2025-06-18` or `2025-11-25` was required.

- [ ] **Step 6: Commit**
```
git add docs/superpowers/PHASE-2-FINDINGS.md
git commit -m "test: verify live empty MCP server (initialize + tools/list) in wp-env"
```

---

### Task 5: PHPUnit harness (so Phase 3 can start green / TDD)

**Files:**
- Create: `phpunit.xml.dist`
- Create: `tests/bootstrap.php`
- Create: `tests/SmokeTest.php`

- [ ] **Step 1: Write `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
	bootstrap="tests/bootstrap.php"
	colors="true"
	failOnWarning="true"
	failOnRisky="true">
	<testsuites>
		<testsuite name="wpmcp-modern">
			<directory suffix="Test.php">tests</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 2: Write `tests/bootstrap.php`**

```php
<?php
declare(strict_types=1);

// wp-phpunit provides the WordPress test scaffolding.
$_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/wordpress-mcp-modern.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 3: Write `tests/SmokeTest.php`**

```php
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
}
```

- [ ] **Step 4: Run the test suite inside wp-env**

Run:
```
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wordpress-mcp-modern \
  env WP_PHPUNIT__DIR=/var/www/html/wp-content/plugins/wordpress-mcp-modern/vendor/wp-phpunit/wp-phpunit \
  vendor/bin/phpunit
```
Expected: `OK (2 tests, …)`.

**BRANCH — `WP_PHPUNIT__DIR` / bootstrap path errors:** print the wp-phpunit path with `npx wp-env run tests-cli ls vendor/wp-phpunit/wp-phpunit/includes` and correct the env var. **BRANCH — Abilities API missing in the *tests* site:** if Task 3 needed the abilities-api plugin, also ensure the tests environment loads it (it shares `.wp-env.json` `plugins`). Record the working command in `PHASE-2-FINDINGS.md`.

- [ ] **Step 5: Commit**
```
git add phpunit.xml.dist tests/ docs/superpowers/PHASE-2-FINDINGS.md
git commit -m "test: wp-phpunit harness + smoke tests (green)"
```

---

## Self-Review

**Spec coverage (against modernization design §2, §8, §9):**
- Plugin skeleton & boot flow (design §2) → Task 2. ✓
- Composer dep on mcp-adapter + Jetpack autoloader — *Jetpack autoloader intentionally deferred* (single-plugin env doesn't need conflict resolution; plain `vendor/autoload.php` used). Noted as later hardening. ✓ (scoped)
- Server on `/wp-json/<ns>/<route>` (design §7) → Task 2/4. ✓
- wp-env + Docker-composer verification (design §8) → Tasks 1,3,4,5. ✓
- Open questions #1 (Abilities API), #3 (php-mcp-schema), #4 (create_server arity) → Tasks 3,1,2. ✓ (#2 canonical core abilities is a Phase 3 investigation, correctly out of scope here.)
- Auth parity, abilities, resources, prompts, gating, admin UI → **out of scope for Phase 2 by design** (Phases 3–11), each gets its own plan.

**Placeholder scan:** the ServerProvider permission callback is labeled a Phase-2 placeholder but contains real, working code (not a TODO). No "TBD"/"implement later" steps remain.

**Type/name consistency:** `Plugin::ABILITY_CATEGORY = 'wordpress-mcp'`; `ServerProvider::{SERVER_ID,NAMESPACE,ROUTE}` referenced consistently in Tasks 2–4; endpoint `/wp-json/wpmcp/mcp` matches `NAMESPACE`/`ROUTE`. `McpAdapter::instance()` boot call present in bootstrap and consistent with `create_server` being called via `mcp_adapter_init`.

**Risk note:** the most likely friction points are (a) `php-mcp-schema` Packagist availability and (b) the wp-phpunit runner command — both have explicit BRANCH fallbacks.
