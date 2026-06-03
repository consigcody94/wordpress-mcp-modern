# Phase 2 — Findings (environment & API verification)

Captured during execution of the Phase 2 foundation plan (2026-06-02).

## Dependency resolution (Task 1)

- **`wordpress/php-mcp-schema`**: resolved **v0.1.1 from Packagist** — no path-repo
  fallback needed. (Open Q#3 ✓.)
- **`wordpress/mcp-adapter`**: installed as **`dev-trunk` @ `530a541`** by mirroring
  the local path repo (`/mcp-adapter` in-container). The `"*"` constraint matched
  dev-trunk. This is the exact tree analyzed in the understanding doc (HEAD of the
  clone; a few commits past the v0.5.0 tag).
- **`wp-phpunit/wp-phpunit`**: 6.9.4 (matches WP 6.9 line).
- No fallback branches were triggered.

## `create_server` arity (Task 1 / Open Q#4 ✓)

Installed signature (`vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php:172`)
has **13 parameters**, ending in `?callable $transport_permission_callback = null`.
`ServerProvider::create()` matches this positional list exactly.

## Results (Tasks 3–4) — all green

- **WP core version:** **7.0** (container PHP **8.3.31**).
- **Abilities API (Open Q#1 ✓):** ships in **core** — no `abilities-api` plugin
  needed. `function_exists('wp_register_ability') === true`.
- **Server registered:** `wp mcp-adapter list` →
  `wpmcp-modern  WordPress MCP (Modern)  0.1.0  0/0/0` plus
  `mcp-adapter-default-server` (3 tools).
- **HTTP `initialize`:** 200; `serverInfo.name = "WordPress MCP (Modern)"`;
  **protocolVersion echoed = `2025-06-18`**; `Mcp-Session-Id` response header
  issued (e.g. `fe7c5318-…`). Auth via **Application Password** (HTTP Basic) —
  works with zero JWT code.
- **HTTP `tools/list`:** `{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}` with the
  session id. **Phase 2 DoD met.**
- **STDIO:** `echo '{…tools/list…}' | wp mcp-adapter serve --server=wpmcp-modern
  --user=admin` → `{"result":{"tools":[]}}`. Both transports confirmed.
- **Endpoint:** `POST http://localhost:8888/wp-json/wpmcp/mcp`.

## Notes for later phases

- `Accept: application/json, text/event-stream` is accepted; protocol
  `2025-06-18` negotiates fine (no need to force `2025-11-25`).
- Application Passwords satisfy the Phase-2 `current_user_can('read')` callback;
  JWT parity (Phase 10) is additive, not required for transport to work.
- Container runs PHP 8.3 — safe to use 8.x niceties guarded by the 7.4 floor.
### PHPUnit runner (Task 5 ✓)

wp-env's tests container ships the WP test library at **`WP_TESTS_DIR=/wordpress-phpunit`**
with a ready `wp-tests-config.php` (DB `tests-wordpress` on `tests-mysql`, root/password).
`tests/bootstrap.php` therefore prefers `WP_TESTS_DIR` over the vendored wp-phpunit.

**Working command (use this for all later phases):**
```
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/wordpress-mcp-modern vendor/bin/phpunit
```
Result: `OK (3 tests, 6 assertions)`.

### Standard command cheat-sheet

- Composer: `docker run --rm -v "C:/Users/Cody/WPMCP/wordpress-mcp-modern:/app" -v "C:/Users/Cody/WPMCP/mcp-adapter:/mcp-adapter" -w /app composer:2 <args>`
- WP-CLI: `npx @wordpress/env run cli wp <args>`  (note: `@wordpress/env`, not `wp-env`)
- Tests: `npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/wordpress-mcp-modern vendor/bin/phpunit`
- HTTP endpoint: `POST http://localhost:8888/wp-json/wpmcp/mcp`
