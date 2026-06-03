# WordPress MCP Modernization — Design / Blueprint

> Phase 1 deliverable and the spec the build follows. Companion to
> [2026-06-02-wordpress-mcp-understanding.md](2026-06-02-wordpress-mcp-understanding.md).
> Goal: re-platform `Automattic/wordpress-mcp` onto `WordPress/mcp-adapter` +
> the Abilities API ("Greenfield, full parity"), verified in wp-env.

## 1. Goal & approach

Build a **new plugin** (working name **`wordpress-mcp-modern`**, slug TBD) that:

1. Depends on `wordpress/mcp-adapter` via Composer (Jetpack Autoloader to avoid
   version clashes).
2. Registers every wordpress-mcp capability as a **WordPress Ability**.
3. Creates one MCP server exposing those abilities, with a JWT-capable
   `transport_permission_callback` for parity, and Application-Password support.
4. Reproduces the gating model (master enable, CRUD-type gates, per-tool
   toggles, REST-CRUD mode) and a slim admin UI (settings + tokens + inspector).
5. Is verified end-to-end in `@wordpress/env` (WP 6.9+/7.0) using Docker for
   Composer and wp-cli, plus the MCP Inspector.

**Non-goals (YAGNI):** preserving the old `/wp/v2/wpmcp*` endpoints (greenfield
accepts the breaking URL change); SSE streaming (adapter doesn't implement it
yet); faithfully reproducing the bugs in §8 of the understanding doc.

## 2. Target architecture

```
wordpress-mcp-modern/
├── wordpress-mcp-modern.php          # bootstrap: guards, autoload, wire hooks
├── composer.json                     # require wordpress/mcp-adapter, jetpack-autoloader
├── includes/
│   ├── Plugin.php                    # singleton; orchestration
│   ├── Abilities/                    # one registrar per capability group
│   │   ├── AbilityRegistrar.php      # iterates groups, applies gating, registers on wp_abilities_api_init
│   │   ├── RestAliasAbilityFactory.php  # builds an ability from (route, method) — the parity workhorse
│   │   ├── Content/ (Posts, Pages, Cpt)
│   │   ├── Media/        (incl. native get_media_file → image content)
│   │   ├── Users/
│   │   ├── Settings/  +  SiteInfo
│   │   ├── RestCrud/    (list/get-details/run — the generic mode)
│   │   ├── Woo/         (Products, Orders — gated on class_exists WooCommerce)
│   │   ├── Resources/   (5 resource abilities: meta.mcp.type=resource)
│   │   ├── Prompts/     (2 prompt abilities: meta.mcp.type=prompt)
│   │   └── FeatureApi/  (optional bridge, if features_adapter_enabled)
│   ├── Mcp/
│   │   └── ServerProvider.php         # on mcp_adapter_init → create_server(...) with gated ability list
│   ├── Auth/
│   │   ├── JwtManager.php             # issue / validate / revoke; honors WPMCP_JWT_SECRET_KEY (fixed)
│   │   ├── JwtRestRoutes.php          # jwt-auth/v1 token|revoke|tokens (parity)
│   │   └── TransportPermission.php    # the create_server callback: Bearer JWT | App Pwd | cookie → wp_set_current_user
│   ├── Admin/
│   │   ├── SettingsPage.php           # server-rendered settings screen
│   │   ├── SettingsStore.php          # options + gating decisions (single source of truth)
│   │   └── (optional) assets/         # minimal vanilla-JS for token mgmt + inspector
│   └── Support/                       # SchemaFromRestRoute, capability helpers, content-block helpers
├── tests/                            # PHPUnit (wp-phpunit) + a JSON-RPC integration harness
└── docs/
```

**Boot flow:** `wordpress-mcp-modern.php` → check `class_exists(McpAdapter)` and
`function_exists('wp_register_ability')` (else admin notice + bail) → load
autoloader → `Plugin::instance()`. `Plugin` hooks:
- `wp_abilities_api_init` → `AbilityRegistrar::register_all()` (registers the
  *enabled* abilities only).
- `mcp_adapter_init` → `ServerProvider::create()` → `create_server(...)` listing
  the enabled tool/resource/prompt ability names + the transport permission
  callback.
- `rest_api_init` → `JwtRestRoutes::register()`.
- `admin_menu`/`admin_init` → `SettingsPage`.
- `cli_init` is handled by mcp-adapter's own `wp mcp-adapter serve`.

## 3. Ability design strategy (the key decision)

Most old tools are `rest_alias` proxies. Two ways to express them as abilities:

- **(A) REST-proxy abilities** — an ability whose `execute_callback` builds a
  `WP_REST_Request` and `rest_do_request`s the same core/WC route; input schema
  is auto-derived by introspecting the route's registered args (exactly what
  `RegisterMcpTool::get_args_from_rest_api` did). **Pro:** parity for ~60 tools
  with one factory; identical permission behavior; low risk. **Con:** an ability
  that wraps REST is less "pure."
- **(B) Direct-WP-call abilities** — `execute_callback` calls `wp_insert_post`
  etc. with hand-written schemas + explicit capability checks. **Pro:** cleanest,
  best schemas. **Con:** ~60 hand-written units; behavior-drift risk.

**Decision: hybrid, factory-first.** Build **`RestAliasAbilityFactory`** (Approach
A) to cover the bulk and guarantee parity cheaply, and hand-write **native
abilities** only where the old plugin already was native or where REST-proxy is
a poor fit: `get_site_info`, `wp_get_media_file` (base64→image content),
`wp_upload_media` (base64 preCallback), the CPT tools, the 3 REST-CRUD tools,
resources, and prompts. **Before writing content abilities, verify whether WP
6.9 core already ships canonical post/user abilities** (understanding doc §9) —
if it does, expose those instead of re-creating.

Each ability conforms to the adapter's contract: `label`, `description`,
`category` (registered on `wp_abilities_api_categories_init`), object-typed
`input_schema`, `output_schema`, `execute_callback`, `permission_callback`, and
`meta` (annotations; `mcp.type` for resource/prompt; `mcp.uri` for resources).
Tool names: the Abilities API expects a `namespace/slug` ability name, and
`McpNameSanitizer` rewrites `/` → `-` (so ability `wordpress-mcp/wp_get_post`
would surface as tool `wordpress-mcp-wp_get_post`). To preserve the **exact**
old tool names (`wp_get_post`) for client parity, map each ability to its legacy
name via the **`mcp_adapter_tool_name` filter** (confirmed present in
`McpNameSanitizer`). Decide in Phase 3 whether exact-name parity is worth the
filter indirection or whether a stable `wpmcp_`-prefixed scheme is preferable.

## 4. Capability mapping (old → ability)

| Old | New ability | Kind | Permission |
|---|---|---|---|
| `wp_*` posts/pages/users/settings/media (rest_alias) | `RestAliasAbilityFactory` from the same route+method | tool | inherit route's `permission_callback` (called explicitly in the ability) |
| `get_site_info` | native ability | tool | `manage_options` |
| `wp_get_media_file` | native ability → `meta.mcp` returns image content via `ContentBlockHelper` | tool | explicit (fix the old `__return_true`) |
| `wp_upload_media` | native ability (base64 decode → media_handle_sideload) | tool | `upload_files` |
| `wp_*_cpt`, `wp_list_post_types` | native abilities | tool | `edit_posts` (note old quirk; consider tightening delete) |
| `list_api_functions` / `get_function_details` / `run_api_function` | native abilities (fix the shadowing bug) | tool | explicit; per-method CRUD self-check |
| `wc_*` products/orders/reports | factory, registered only if `class_exists('WooCommerce')` | tool | inherit WC route perms |
| `WordPress://site-info` … `site-settings` (5) | resource abilities (`meta.mcp.type=resource`, `meta.mcp.uri`) | resource | `manage_options` |
| `get-site-info`, `analyze-sales` | prompt abilities (`meta.mcp.type=prompt`, `meta.mcp.arguments`) | prompt | `manage_options` (parity) |
| `wp_feature_*` | optional FeatureApi bridge | tool | feature's own callback |

## 5. Auth design

- **`TransportPermission::check(WP_REST_Request): bool|WP_Error`** (the 13th
  `create_server` arg). Order: (1) `Authorization: Bearer <jwt>` → validate via
  `JwtManager` → on success `wp_set_current_user($uid)` → `true`; (2) else if a
  user is already authenticated (cookie, or **Application Password** validated by
  WP core's Basic-auth handling) → `true`; (3) else `WP_Error(401)`.
  Must set the current user so mcp-adapter sessions (user-meta, per-user) work.
- **`JwtManager`** — `firebase/php-jwt` HS256. Secret precedence:
  **`WPMCP_JWT_SECRET_KEY` constant (fix) → option `wpmcp_jwt_secret_key`
  (auto-generate if absent)**. Keep the **stateful registry** (`jwt_token_registry`)
  so revocation works; validate signature + exp + `jti` present + not revoked.
- **`JwtRestRoutes`** — parity routes `POST jwt-auth/v1/token`,
  `POST jwt-auth/v1/revoke`, `GET jwt-auth/v1/tokens`, same duration rules
  (1h–30d, `wpmcp_jwt_max_expiration_time` filter).
- App Passwords: free via core Basic-auth + step (2). Documented as the
  recommended low-maintenance path; JWT remains for parity.

## 6. Settings & gating model

`SettingsStore` is the single source of truth, reading the same option names for
familiarity: `wordpress_mcp_settings` (`enabled`, `features_adapter_enabled`,
`enable_create_tools`, `enable_update_tools`, `enable_delete_tools`,
`enable_rest_api_crud_tools`) and `wordpress_mcp_tool_states` (per-tool, default-on).

Gating is applied in **two coordinated places**:
1. `AbilityRegistrar` registers an ability only if it would be enabled (master on,
   type allowed, per-tool on, REST-CRUD-mode rules) — keeps `wp_get_abilities()`
   clean.
2. `ServerProvider` passes only the enabled ability names into `create_server`.

Semantics preserved: `read`/`action` always on when enabled; create/update/delete
gated; `enable_rest_api_crud_tools` is a **mode switch** that swaps the curated
tools for the 3 generic ones. **Admin UI:** a server-rendered `SettingsPage`
(WordPress Settings API + nonce, no heavy React build) with: master + CRUD +
REST-CRUD toggles, a per-tool enable table, a JWT token panel (issue/list/revoke
against the parity routes), and a read-only tool/resource/prompt inspector that
calls the MCP server. (Porting the original React SPA is possible later if full
visual parity is wanted — deferred, YAGNI.)

## 7. Endpoints & client compatibility

- New HTTP endpoint: `POST /wp-json/<namespace>/<route>` (e.g.
  `/wp-json/wpmcp/mcp`). Clients must send `Mcp-Session-Id` (from `initialize`)
  on subsequent calls and a supported `Mcp-Protocol-Version`.
- STDIO via `wp mcp-adapter serve --server=<id> --user=<login>`.
- The old `/wp/v2/wpmcp*` URLs are **not** preserved (greenfield). If backward
  compatibility becomes a requirement, that's the "strangler" approach (B) and a
  separate spec.

## 8. Testing & verification strategy (no local PHP)

- **Composer** via Docker: `docker run --rm -v "$PWD":/app -w /app composer:2 install`.
- **WordPress** via `npx @wordpress/env start` with `.wp-env.json` pinning WP
  6.9+/7.0 and mounting this plugin (+ the abilities-api package if core doesn't
  bundle it — verify first) (+ WooCommerce for Woo tests).
- **PHPUnit** (wp-phpunit) for ability unit tests, run inside the wp-env phpunit
  container.
- **Integration harness:** drive the live server through wp-cli STDIO
  (`echo '{json-rpc}' | npx wp-env run cli wp mcp-adapter serve …`) and through
  HTTP (curl with Bearer + `Mcp-Session-Id`), asserting `initialize` →
  `tools/list` → `tools/call` parity per ability group.
- **MCP Inspector** (`npx @modelcontextprotocol/inspector`) for manual
  end-to-end confirmation.
- **Definition of done per phase:** the phase's abilities appear in `tools/list`
  and a representative `tools/call` returns correct content, with auth enforced.

## 9. Phased implementation plan

- **Phase 2 — Scaffold & live empty server.** Plugin skeleton, composer dep,
  Jetpack autoloader, bootstrap guards, `ServerProvider` with an empty ability
  list, wp-env up, verify `initialize`/`tools/list` answer over HTTP + STDIO.
  Resolve the WP-6.9-Abilities-API availability question here.
- **Phase 3 — RestAliasAbilityFactory + Content** (posts, pages, categories,
  tags). First real abilities; prove the factory + parity tests.
- **Phase 4 — Media** (incl. native file/image + base64 upload).
- **Phase 5 — Users.**
- **Phase 6 — Settings + Site info + the 5 Resources.**
- **Phase 7 — CPT + generic REST-CRUD mode** (with bug fixes + mode switch).
- **Phase 8 — WooCommerce** (products, orders/reports; gated).
- **Phase 9 — Prompts** (+ optional Feature-API bridge).
- **Phase 10 — Auth parity** (JwtManager + routes + transport callback + App
  Passwords) and **gating** wired to settings.
- **Phase 11 — Admin settings page & inspector; packaging** (.distignore,
  readme.txt, CI: phpcs/phpstan/phpunit), full end-to-end verification.

(Auth in Phase 10 is late because earlier phases can authenticate via wp-cli
`--user` and Application Passwords; JWT parity is additive.)

## 10. Open questions / investigation items

1. **Does WP 6.9/7.0 core ship the Abilities API**, or must
   `wordpress/abilities-api` be installed alongside? (Blocks Phase 2.)
2. **Do canonical post/user/etc. abilities already exist** in core/another
   plugin? If yes, reuse instead of re-creating (affects Phases 3–6).
3. Confirm `wordpress/php-mcp-schema` resolves cleanly under Composer with
   `minimum-stability: dev`.
4. Confirm the exact `create_server` arity/signature against the installed
   adapter version at build time (docs claimed "unchanged" but code has 13
   params — pin to the cloned v0.5.0).

## 11. Decisions captured (override at spec review)

- **D1 Approach:** Greenfield on mcp-adapter + Abilities API. *(approved)*
- **D2 Scope:** full parity incl. WooCommerce (gated), REST-CRUD mode, JWT, and
  a slim settings/token/inspector UI. *(approved; trimmable)*
- **D3 Tools:** factory-first (REST-proxy abilities) + targeted native abilities.
- **D4 Auth:** JWT as transport-permission callback **and** keep parity token
  routes + stateful registry; fix the secret-key constant; App Passwords
  supported.
- **D5 UI:** server-rendered settings page (not a React SPA) unless full visual
  parity is requested.
- **D6 Endpoints:** adopt `/wp-json/<ns>/<route>`; do **not** preserve
  `/wp/v2/wpmcp*` (no strangler) under this spec.
- **D7 Bugs:** fix (don't reproduce) the issues in understanding-doc §8.
- **D8 Name:** plugin working name `wordpress-mcp-modern`; final slug TBD.
