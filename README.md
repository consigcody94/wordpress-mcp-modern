# WordPress MCP (Modern)

A modern re-implementation of [`Automattic/wordpress-mcp`](https://github.com/Automattic/wordpress-mcp)
(now deprecated) built on the official [`WordPress/mcp-adapter`](https://github.com/WordPress/mcp-adapter)
and the **WordPress Abilities API** (WordPress Core 6.9+).

Instead of bespoke "tools," every capability is a WordPress **Ability**
(`wp_register_ability`) that mcp-adapter exposes as an MCP tool, resource, or
prompt — so the same capabilities are reusable by any AI building block, not
just MCP.

## Status

- **63 MCP tools** (43 core + 20 WooCommerce when active), **5 resources**, **2 prompts**.
- HTTP (Streamable, MCP 2025-06-18) and STDIO (WP-CLI) transports.
- JWT auth + WordPress Application Password auth.
- Settings screen with per-tool toggles, CRUD gates, and an experimental
  generic REST-CRUD mode.
- 54 PHPUnit tests / 347 assertions; verified end-to-end in `@wordpress/env`.

## Requirements

- WordPress **6.9+** (ships the Abilities API).
- PHP **7.4+**.
- Composer (the plugin depends on `wordpress/mcp-adapter`).

## Installation

```bash
cd wp-content/plugins/wordpress-mcp-modern
composer install --no-dev
wp plugin activate wordpress-mcp-modern
```

### Local development (Docker, no local PHP required)

```bash
# Install PHP deps via the Composer Docker image
docker run --rm -v "$PWD":/app -w /app composer:2 install

# Start WordPress 7.0 + tests env
npx @wordpress/env start

# Run the test suite
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/wordpress-mcp-modern vendor/bin/phpunit
```

## The MCP endpoint

```
POST /wp-json/wpmcp/mcp        # HTTP (Streamable) transport
wp mcp-adapter serve --server=wpmcp-modern --user=admin   # STDIO transport
```

`initialize` returns an `Mcp-Session-Id` response header; include it (and a
supported `Mcp-Protocol-Version`) on subsequent requests.

## Authentication

Two options, both enforced by the server's transport permission callback:

1. **Application Passwords** (recommended) — standard WordPress HTTP Basic auth.
2. **JWT** — issue/list/revoke via:
   - `POST /wp-json/jwt-auth/v1/token` (current user, or `username`/`password`; optional `expires_in`)
   - `GET  /wp-json/jwt-auth/v1/tokens` (admin)
   - `POST /wp-json/jwt-auth/v1/revoke` (admin; body `{ "jti": "..." }`)
   - Send the token as `Authorization: Bearer <jwt>`.
   - Secret: define `WPMCP_JWT_SECRET_KEY` in `wp-config.php`, else an option is
     auto-generated. Tokens are stateful (revocable independent of expiry).

Tokens can also be generated/revoked from **Settings → WordPress MCP**.

## Capability surface

| Group | Tools |
|---|---|
| Posts | search, get, add, update, delete (+ none) |
| Pages | search, get, add, update, delete |
| Taxonomy | list/add/update/delete for categories and tags |
| Users | search, get, add, update, delete, current-user get/update |
| Settings | get/update general settings |
| Custom post types | list types, search/get/add/update/delete any post type |
| Media | list, search, get, update, delete, **upload (base64)**, get-file |
| Core (reused) | `get_site_info`, `get_user_info`, `get_environment_info` |
| WooCommerce (when active) | products, product categories/tags, orders, reports |
| Generic (REST-CRUD mode) | `list_api_functions`, `get_function_details`, `run_api_function` |

**Resources:** `wordpress://site-info`, `plugin-info`, `theme-info`,
`user-info`, `site-settings`.
**Prompts:** `get-site-info`, `analyze-sales`.

Legacy tool names (e.g. `wp_posts_search`) are preserved via the
`mcp_adapter_tool_name` filter even though ability names are dash-cased.

## Settings (Settings → WordPress MCP)

- Master enable.
- Enable create / update / delete tools (type gates; read/action always on).
- Per-tool enable toggles.
- Experimental **REST-CRUD mode** — replaces the curated tools with three
  generic "run any REST route" tools.
- JWT token management.

## Architecture

```
includes/
├── Plugin.php                 # wires hooks; boots mcp-adapter
├── Mcp/ServerProvider.php     # create_server(...) with the gated ability lists
├── Abilities/
│   ├── AbilityRegistrar.php   # single source of truth; gating; name mapping
│   ├── RestProxyAbility.php   # ability that proxies a REST route (real validation/perms)
│   ├── NativeAbility.php       # ability backed by a PHP callback
│   ├── ResourceAbility.php / PromptAbility.php
│   └── *Abilities.php         # Content, Taxonomy, Users, Settings, Cpt, Media, Woo, Resource, Prompt, RestCrud
├── Auth/                      # JwtManager, JwtRestRoutes, TransportPermission
└── Admin/                     # SettingsStore, SettingsPage
```

Most tools are `RestProxyAbility` definitions (explicit input schema + dispatch
via `rest_do_request`, inheriting the target endpoint's validation and
permissions). Genuinely custom behaviour (CPTs over arbitrary post types,
base64 media upload, resources, prompts, generic REST-CRUD) uses native
abilities.

## Migrating from `Automattic/wordpress-mcp`

- The endpoint changes from `/wp/v2/wpmcp[/streamable]` to `/wp-json/wpmcp/mcp`.
- Tool **names** are preserved (`wp_posts_search`, `wc_get_product`, …).
- App Passwords and JWT both work; the `jwt-auth/v1` routes mirror the legacy API
  (and the `WPMCP_JWT_SECRET_KEY` constant is now honoured).
- See `docs/superpowers/specs/` for the full understanding + design documents.

## License

GPL-2.0-or-later.
