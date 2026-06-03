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

## Pending (filled in as Tasks 3–5 run)

- [ ] WP core version (Task 3 Step 3): _____
- [ ] Abilities API present in core vs. plugin (Task 3 Step 4): _____
- [ ] Server `wpmcp-modern` registered (Task 3 Step 6): _____
- [ ] Live `initialize` + `tools/list` over HTTP (Task 4): protocol version echoed = _____
- [ ] STDIO smoke (Task 4 Step 4): _____
- [ ] Working phpunit runner command (Task 5 Step 4): _____
