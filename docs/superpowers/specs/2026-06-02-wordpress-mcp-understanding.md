# WordPress MCP — Understanding & Gap Analysis

> Phase 0 deliverable. Reference document that the modernization design
> ([2026-06-02-wordpress-mcp-modernization-design.md](2026-06-02-wordpress-mcp-modernization-design.md))
> builds on. All claims below were verified by reading the cloned source of
> both repositories on 2026-06-02.

## 1. TL;DR

- **`Automattic/wordpress-mcp` (v0.2.5) is officially deprecated.** Its README's
  first line directs users to migrate to `WordPress/mcp-adapter`.
- **`WordPress/mcp-adapter` (v0.5.0) is the successor**, part of the official
  "AI Building Blocks for WordPress" initiative. It is a thin, layered library
  that exposes the **WordPress Abilities API** (landing in WP Core **6.9**) as
  MCP tools / resources / prompts.
- "Bringing wordpress-mcp to modern" is a **re-platform, not a version bump**:
  the unit of capability changes from *bespoke PHP tool classes* to
  *Abilities*, the transport/auth/UI layers are replaced, and several
  wordpress-mcp features have **no out-of-the-box equivalent** in mcp-adapter.

## 2. The two repositories at a glance

| | `Automattic/wordpress-mcp` | `WordPress/mcp-adapter` |
|---|---|---|
| Version | 0.2.5 (deprecated) | 0.5.0 (active) |
| Distribution | Monolithic plugin (zip / git) | Composer package first; plugin secondary |
| PHP / WP | PHP 8.0 / WP 6.4 | PHP 7.4 / WP **6.9** |
| Capability primitive | Hand-written `Mcp*Tools` classes | **WordPress Abilities** (`wp_register_ability`) |
| PSR-4 root | `Automattic\WordpressMcp\` → `includes/` | `WP\MCP\` → `includes/` |
| Transports | `McpStdioTransport`, `McpStreamableTransport` (bespoke) | `HttpTransport` (unified) + WP-CLI STDIO bridge |
| HTTP endpoint(s) | `/wp/v2/wpmcp`, `/wp/v2/wpmcp/streamable` | `/wp-json/<namespace>/<route>` |
| Auth | Built-in stateful JWT + App-Password deferral | Pluggable `transport_permission_callback`; App Passwords via proxy |
| Admin UI | React SPA (6 tabs) under Settings → MCP | None (library) |
| Protocol DTOs | Hand-built arrays | `wordpress/php-mcp-schema` typed DTOs |
| MCP protocol versions | `initialize` reports `2025-03-26` | `2025-11-25`, `2025-06-18`, `2024-11-05` |
| Sessions | None (stateless per request) | `Mcp-Session-Id`, stored in user meta, per-user |
| Multi-server | No (single implicit server) | Yes (`create_server` registry) |
| Dependency injection | None | Error-handler + observability handler per server (interfaces, null-object) |

## 3. Architecture comparison

### 3.1 wordpress-mcp (old)

```
wordpress-mcp.php  ── add_action('init', wordpress_mcp_init)
  └─ WpMcp (singleton, only constructs if settings['enabled'])
       ├─ init_default_tools()       → new Mcp*Tools()         (each add_action('wordpress_mcp_init', register))
       ├─ init_default_resources()   → new Mcp*Resource()
       ├─ init_default_prompts()     → new Mcp*()
       ├─ (optional) WpFeaturesAdapter  (if features_adapter_enabled)
       └─ on rest_api_init @20000 → do_action('wordpress_mcp_init')
  └─ new McpStdioTransport(WpMcp)     → POST /wp/v2/wpmcp           (WP-style envelope, is_user_logged_in)
  └─ new McpStreamableTransport(WpMcp)→ POST /wp/v2/wpmcp/streamable (JSON-RPC 2.0, JWT/OAuth/cookie)
  └─ new Settings()                   → admin SPA + admin-ajax persistence
  └─ new JwtAuth()                    → jwt-auth/v1/{token,revoke,tokens}
```

Both transports extend `McpTransportBase`, which builds the 5 method handlers
(`Initialize`, `Tools`, `Resources`, `Prompts`, `System`) and dispatches via a
`match` on the JSON-RPC method. Tools are stored in parallel arrays on the
`WpMcp` singleton (`$tools`, `$tools_callbacks`, …).

### 3.2 mcp-adapter (new)

```
mcp-adapter.php → Plugin::instance()
  └─ guard: requires wp_register_ability() (Abilities API) else admin notice + bail
  └─ McpAdapter::instance()
       ├─ hooks init() on rest_api_init @15 (or 'init' under WP-CLI)
       └─ init():
            ├─ maybe_create_default_server()  (filter: mcp_adapter_create_default_server)
            ├─ do_action('mcp_adapter_init', $adapter)   ← YOU call create_server() here
            └─ register_wp_cli_commands()
  └─ HttpTransport per server → register_rest_route(ns, route, POST|GET|DELETE)
```

Layering inside `includes/`:
`Core/` (McpAdapter, McpServer, McpComponentRegistry, McpTransportFactory,
McpVersionNegotiator) · `Abilities/` (discover / get-info / execute meta-tools) ·
`Domain/` (Tools, Resources, Prompts — each with a `RegisterAbilityAsMcp*`
builder + validator; plus `Utils/` SchemaTransformer, McpNameSanitizer,
McpAnnotationMapper, AbilityArgumentNormalizer) · `Handlers/` (Initialize, Tools,
Resources, Prompts, System) · `Infrastructure/` (ErrorHandling, Observability —
both with interface + default + null-object) · `Transport/` (HttpTransport +
Infrastructure: SessionManager, RequestRouter, HttpRequestHandler,
HttpSessionValidator, JsonRpcResponseBuilder) · `Cli/` (McpCommand,
StdioServerBridge) · `Servers/` (DefaultServerFactory).

### 3.3 The core conceptual shift

In the old plugin a "tool" is a PHP class that registers a name + schema +
callback directly on the MCP server. In the new world a **capability is an
Ability** registered via `wp_register_ability()` on the `wp_abilities_api_init`
hook; mcp-adapter is *only* the protocol/transport bridge that turns abilities
into MCP components. Abilities are reusable by any AI building block, not just
MCP — that reusability is the whole point of the re-platform.

## 4. Capability inventory (what we must port)

### 4.1 Tools (~60 curated)

Most tools are **`rest_alias`** — thin proxies that introspect a core/WC REST
route's args to build an input schema and dispatch via `rest_do_request`,
inheriting that endpoint's `permission_callback`. A minority are
**native-callback** tools.

| Group (class) | Tools | Backing | Notes |
|---|---|---|---|
| Posts (`McpPostsTools`) | `wp_posts_search`, `wp_get_post`, `wp_add_post`, `wp_update_post`, `wp_delete_post`, `wp_list_categories`, `wp_add_category`, `wp_update_category`, `wp_delete_category`, `wp_list_tags`, `wp_add_tag`, `wp_update_tag`, `wp_delete_tag` | `rest_alias` → `/wp/v2/posts`, `/categories`, `/tags` | content as Gutenberg blocks |
| Pages (`McpPagesTools`) | `wp_pages_search`, `wp_get_page`, `wp_add_page`, `wp_update_page`, `wp_delete_page` | `rest_alias` → `/wp/v2/pages` | parent/order extras |
| Media (`McpMediaTools`) | `wp_list_media`, `wp_get_media`, `wp_get_media_file`, `wp_upload_media`, `wp_update_media`, `wp_delete_media`, `wp_search_media` | mixed | `wp_get_media_file` = **native**, returns base64 → MCP image block; `wp_upload_media` uses a base64 preCallback; `delete` needs `force=true` |
| Users (`McpUsersTools`) | `wp_users_search`, `wp_get_user`, `wp_add_user`, `wp_update_user`, `wp_delete_user`, `wp_get_current_user`, `wp_update_current_user` | `rest_alias` → `/wp/v2/users` | `context=edit` forced on several |
| Settings (`McpSettingsTools`) | `wp_get_general_settings`, `wp_update_general_settings` | `rest_alias` → `/wp/v2/settings` | `manage_options` |
| Site info (`McpSiteInfo`) | `get_site_info` | **native** | requires `manage_options`; aggregates plugins/themes/users |
| CPT (`McpCustomPostTypesTools`) | `wp_list_post_types`, `wp_cpt_search`, `wp_get_cpt`, `wp_add_cpt`, `wp_update_cpt`, `wp_delete_cpt` | mostly native (`WP_Query`/`wp_insert_post`) | all flagged `disabled_by_rest_crud`; perms only check `edit_posts` |
| Woo products (`McpWooProducts`) | `wc_*_product`, `wc_*_product_category`, `wc_*_product_tag`, `wc_*_product_brand` (17) | `rest_alias` → `/wc/v3/products*` | only if `class_exists('WooCommerce')`; brands need the Brands extension |
| Woo orders/reports (`McpWooOrders`) | `wc_orders_search`, `wc_reports_*` (7, read-only) | `rest_alias` → `/wc/v3/orders`, `/reports/*` | no order writes |

**Experimental generic CRUD (`McpRestApiCrud`)** — gated by
`enable_rest_api_crud_tools`. When **on**, it *disables every `rest_alias` and
`disabled_by_rest_crud` tool* and replaces them with three generic tools:
`list_api_functions`, `get_function_details`, `run_api_function` (dispatches any
REST route; per-method create/update/delete self-checks). Treat it as a **mode
switch**, not a toggle. Exclusion list: `/`, `/batch/v1`, and any route
containing `oembed`, `autosaves`, `revisions`, `jwt-auth`.

### 4.2 Resources (5, all `application/json`, all `manage_options`)

`WordPress://site-info`, `WordPress://plugin-info`, `WordPress://theme-info`,
`WordPress://user-info`, `WordPress://site-settings`. Note the custom
`WordPress://` URI scheme. Reads are gated at the handler by `manage_options`.

### 4.3 Prompts (2)

`get-site-info` (arg `info_type`, optional) and `analyze-sales` (arg
`time_span`, required). Argument substitution is literal `{{var}}` replacement.

### 4.4 Feature-API adapter

`WpFeaturesAdapter` (optional) bridges the older WordPress Feature API: it
iterates `wp_feature_registry()->get()` and registers each feature as a
`wp_feature_<slug>` tool. Soft dependency — silently inert if the Feature API
isn't present.

## 5. Auth & transport

### 5.1 wordpress-mcp JWT (`JwtAuth`)

- `firebase/php-jwt`, **HS256**. Secret = option `wpmcp_jwt_secret_key`,
  auto-generated (64 chars) on first use.
  **The `WPMCP_JWT_SECRET_KEY` constant documented in the README is never read
  by the code** — a real bug; existing installs do not honor it.
- Claims: `iss`, `iat`, `exp`, `user_id`, `jti`. **Stateful**: every token is
  recorded in option `jwt_token_registry`; validity requires the `jti` to exist,
  be non-revoked, and unexpired (so signature alone is insufficient).
- Routes (namespace `jwt-auth/v1`): `POST /token` (public; logs in via
  username/password or current user), `POST /revoke` (`manage_options`),
  `GET /tokens` (`manage_options`).
- Duration: min 1h, default 1h, max 30d (filter `wpmcp_jwt_max_expiration_time`).
- Two auth hooks: `rest_authentication_errors` (only for `/wp/v2/wpmcp` paths;
  defers `Basic` to core → App Passwords) and `wpmcp_authenticate_request`
  (fired by the Streamable transport permission callback).

### 5.2 Transports

| | STDIO transport (`/wp/v2/wpmcp`) | Streamable transport (`/wp/v2/wpmcp/streamable`) |
|---|---|---|
| Envelope | WordPress (`rest_ensure_response` / `WP_Error`) | strict JSON-RPC 2.0 |
| Permission | `is_user_logged_in()` (lets App Passwords through) | `wpmcp_authenticate_request` (JWT) → OAuth Passport → cookie |
| Params | `params ?? whole-body` fallback (admin UI relies on this) | `params` only; batches supported |
| Quirks | `[DEBUG: …]` suffix on errors | demands `Accept: application/json, text/event-stream` but never streams SSE |
| Sessions | none | none |

`initialize` reports `protocolVersion: 2025-03-26` and advertises
tools/resources/prompts/logging/completion/roots capabilities.

### 5.3 mcp-adapter auth (target)

- **Per-server `transport_permission_callback`** (13th arg of `create_server`):
  `function(WP_REST_Request): bool|WP_Error`. Returning `WP_Error`/`false`
  denies (fail-closed). Default when unset is `current_user_can('read')`.
- **Sessions are tied to a WP user**: `initialize` creates a session (requires
  an authenticated user, returns `Mcp-Session-Id`); every other request
  requires that header + a valid stored session + an authenticated user.
  ⇒ **A JWT callback must `wp_set_current_user()` before returning `true`**, or
  all non-initialize calls fail `unauthorized`.
- Per-tool/resource/prompt permission is a **separate** layer: each ability's
  own `permission_callback` / `check_permissions()` still runs.
- WP-CLI STDIO (`wp mcp-adapter serve --server=… --user=…`) bypasses HTTP
  sessions and the transport permission callback entirely.

## 6. Admin UI & settings (old)

Three options back the whole plugin:
- `wordpress_mcp_settings` — `enabled`, `features_adapter_enabled`,
  `enable_create_tools`, `enable_update_tools`, `enable_delete_tools`,
  `enable_rest_api_crud_tools` (all default `false`).
- `wordpress_mcp_tool_states` — per-tool `{name: bool}` (default-on).
- JWT state (`wpmcp_jwt_secret_key`, `jwt_token_registry`).

**Gating semantics:** a tool registers only if
`tool_enabled && tool_type_enabled && !disabled_by_rest_crud`. `read`/`action`
types are always allowed when MCP is on; `create`/`update`/`delete` require their
flag. Settings + tool toggles persist via **admin-ajax** (`wordpress_mcp_save_settings`,
`wordpress_mcp_toggle_tool`) — **not** REST (the localized
`wordpress-mcp/v1/settings` URL is dead config). The React tabs
(Tools/Resources/Prompts) call the STDIO endpoint directly with JSON-RPC bodies.

## 7. Gap analysis — what mcp-adapter does NOT give us for free

| wordpress-mcp feature | mcp-adapter equivalent | Migration work |
|---|---|---|
| ~60 curated tools | none (you register abilities) | re-express each as an Ability (see design §3) |
| `rest_alias` auto-schema-from-route | none | port the route-introspection logic into an ability factory |
| JWT auth + token UI | `transport_permission_callback` only | re-implement JWT as a callback; rebuild token issue/list/revoke routes + registry |
| App Password support | works via default cap check / proxy | mostly free; verify |
| React admin SPA (6 tabs) | none | rebuild a (slim) settings + token + inspector screen |
| Per-tool enable / CRUD gates / REST-CRUD mode | none | reproduce as a registry that decides which abilities go into `create_server` |
| 5 resources (`WordPress://…`) | abilities w/ `meta.mcp.type=resource` + `meta.mcp.uri` | re-express; note URI scheme + `manage_options` gate |
| 2 prompts | abilities w/ `meta.mcp.type=prompt` or `McpPromptBuilder` | re-express |
| Feature-API adapter | none | optional; re-bridge if `features_adapter_enabled` desired |
| `/wp/v2/wpmcp*` endpoints | `/wp-json/<ns>/<route>` | **breaking** for existing clients (greenfield accepts this) |
| Custom transports / observability / multi-server | richer in adapter | free upgrade |

## 8. Bugs & quirks to NOT faithfully reproduce

1. `WPMCP_JWT_SECRET_KEY` constant is documented but never read — **fix** by
   honoring a constant in the new JWT callback.
2. `McpRestApiCrud::get_function_details` returns the **first** route's args due
   to a variable-shadowing bug (`if ($route === $route …)`) — **fix**.
3. The `get-site-info` prompt embeds a Handlebars `{{#if}}` that the literal
   `{{var}}` substituter never evaluates — leaks template text — **fix**.
4. SchemaValidator treats `pattern` as a literal string (preg_quoted) and
   `$ref` is an unresolved stub — avoid relying on either.
5. `wp_get_media_file` and all `McpRestApiCrud` tools use `__return_true`
   permission (effective auth is the dispatched route's) — make permissions
   explicit in the new abilities.
6. Empty / `"null"` arguments are stripped before execution — can drop
   legitimate `0`/`false`/`""` — don't replicate blindly.

## 9. Environment & constraints (local dev box)

- **No local PHP / Composer.** Mitigated by Docker: run Composer via the
  official `composer` Docker image; run WordPress via `@wordpress/env` (Docker).
  Node 24 + npm present.
- mcp-adapter requires **WP 6.9+** and the **Abilities API** (`wp_register_ability`).
  Open question to verify in wp-env: whether WP 6.9/7.0 core ships the Abilities
  API or whether the `wordpress/abilities-api` package/plugin must also be
  installed. The adapter bails with an admin notice if it's absent.
- mcp-adapter depends on `wordpress/php-mcp-schema` (`^0.1.0`,
  `minimum-stability: dev`) — pulled by Composer.
- **Open question:** does WP Core 6.9 (or a canonical plugin) already register
  abilities for posts/users/etc.? If so, prefer reusing them over re-creating.
  Verify before writing content abilities.

## 10. Sources

- `wordpress-mcp/` @ tag v0.2.5 (HEAD includes the deprecation-notice commit).
- `mcp-adapter/` @ tag v0.5.0.
- Verified by full source reads of `includes/**` in both repos, the React
  `src/settings/**`, both `composer.json`, both plugin bootstraps, and
  mcp-adapter `docs/guides/**` + `docs/migration/**`.
