# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `wp_get_media_file` now accepts `include_data: true` to return the file contents
  base64-encoded (capped at 5 MB by default, filterable via `wpmcp_media_file_max_bytes`),
  and `as_image: true` to return images as a **native MCP image content block**
  (rendered inline by MCP clients instead of JSON).
- **8 new WooCommerce tools** (28 total): product brands CRUD
  (`wc_list_product_brands`, `wc_add_product_brand`, `wc_update_product_brand`,
  `wc_delete_product_brand` — WooCommerce 9.4+ core brands) and order
  read/write (`wc_get_order`, `wc_add_order`, `wc_update_order`, `wc_delete_order`).
  Total tool count is now 71.
- **React settings UI** on WordPress's bundled `wp-element`/`wp-components`
  (no build step), backed by new `wpmcp/v1/settings` REST routes; the
  server-rendered form remains as the no-JS fallback.
- `wp_get_media_file as_resource: true` returns any file (audio, PDFs, archives…)
  as an MCP embedded blob resource content block.
- **6 comments tools**: search, get, add (reply via `parent`), update,
  moderate (approve/hold/spam/trash), delete.
- **5 plugin/theme tools** (admin-gated): list/activate/deactivate plugins
  (self-deactivation refused) and list/switch themes. Total: **82 tools**.
- **Audit log** (opt-in): ring-buffer record of tool calls, shown in the
  settings UI and at `GET /wpmcp/v1/audit`.
- **Rate limiting** (opt-in): per-user per-minute tool-call budget
  (`wpmcp_rate_limit_per_minute`, default 60) enforced before execution (429).
- **OAuth 2.1 authorization** (experimental, opt-in): RFC 8414/9728 discovery,
  RFC 7591 dynamic client registration, PKCE-only authorization-code flow with
  a consent screen; access tokens are the plugin's revocable JWTs.
- **WP-CLI commands**: `wp wpmcp token generate|list|revoke`,
  `wp wpmcp settings list|set`, `wp wpmcp tools list|enable|disable`.
- **Multisite**: network-admin kill switch (`Network Settings → WordPress MCP`)
  that overrides per-site settings.
- **Plugin Check CI job** (errors gate the build) toward WordPress.org readiness.
- WordPress.org-format `readme.txt`.
- Release workflow: pushing a `v*` tag builds the distributable plugin zip
  (with bundled Composer dependencies) and attaches it to a GitHub Release.
- CI lint job: PHP syntax check on PHP 7.4 and 8.3 plus `composer validate`.
- Contributor docs (`CONTRIBUTING.md`), security policy (`SECURITY.md`),
  `.editorconfig`, Dependabot config, and issue/PR templates.

## [0.1.0] - 2026-06-08

### Added

- Initial implementation on the WordPress Abilities API + mcp-adapter stack:
  63 tools (43 core + 20 WooCommerce), 5 resources, 2 prompts.
- Content, taxonomy, user, settings, custom post type, media, and WooCommerce
  ability groups; legacy `wordpress-mcp` tool names preserved.
- JWT authentication (stateful, revocable HS256) with `jwt-auth/v1` REST routes
  and admin token management; Application Password support via the transport
  permission callback.
- Settings page with master enable, create/update/delete gates, per-tool
  toggles, and experimental REST-CRUD mode.
- PHPUnit suite running against real WordPress via `@wordpress/env`; CI on
  every push and pull request.

[Unreleased]: https://github.com/consigcody94/wordpress-mcp-modern/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/consigcody94/wordpress-mcp-modern/releases/tag/v0.1.0
