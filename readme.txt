=== WordPress MCP (Modern) ===
Contributors: consigcody94
Tags: mcp, ai, abilities-api, rest-api, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any WordPress site into a Model Context Protocol (MCP) server — built on the WordPress Abilities API and the official mcp-adapter.

== Description ==

WordPress MCP (Modern) exposes your site's capabilities to AI agents (Claude, Cursor, VS Code, and any MCP-compatible client) over the **Model Context Protocol**. It is a ground-up re-implementation of the deprecated Automattic/wordpress-mcp plugin on the modern stack: every capability is a **WordPress Ability** (`wp_register_ability()`), and the official **mcp-adapter** library exposes them as MCP tools, resources, and prompts.

= Highlights =

* **82 tools** — posts, pages, taxonomies, users, settings, custom post types, comments, plugins & themes, media (including base64 upload and native MCP image/blob content blocks), plus 28 WooCommerce tools when WooCommerce is active.
* **5 resources** — site, plugin, theme, user, and settings info.
* **2 prompts** — `get-site-info` and `analyze-sales`.
* **Three auth mechanisms** — WordPress Application Passwords (zero setup), stateful revocable HS256 JWTs with a management API and admin UI, and experimental opt-in OAuth 2.1 (PKCE + dynamic client registration) for clients with built-in OAuth flows.
* **Observability** — optional tool-call audit log and per-user rate limiting.
* **Operations-friendly** — WP-CLI commands (`wp wpmcp token|settings|tools`) and a multisite network kill switch.
* **HTTP (Streamable) and STDIO transports** via mcp-adapter.
* **Fine-grained gating** — master enable switch, create/update/delete gates, and per-tool toggles from a React-powered Settings → WordPress MCP screen (with a no-JavaScript fallback).
* **Legacy compatible** — tool names from Automattic/wordpress-mcp (`wp_posts_search`, `wc_get_product`, …) are preserved, and the `jwt-auth/v1` routes mirror the legacy API.

= How it works =

Most tools declare an explicit input schema but execute by dispatching through `rest_do_request()`, so the real WordPress REST validation, sanitization, and permission checks run at call time. Two security layers always apply: the server-wide transport permission (JWT or Application Password) and each ability's own permission callback.

Your MCP endpoint lives at `/wp-json/wpmcp/mcp`.

= Add your own tool =

Any ability registered by your plugin or theme with `'meta' => array( 'mcp' => array( 'public' => true ) )` becomes an MCP tool automatically. See the project README for a complete example.

== Installation ==

1. Download the packaged zip from the GitHub Releases page (it bundles the required Composer dependencies), or clone the repository into `wp-content/plugins/` and run `composer install --no-dev`.
2. Activate **WordPress MCP (Modern)** through the Plugins screen.
3. Create an Application Password under Users → Profile, or generate a JWT under Settings → WordPress MCP.
4. Point your MCP client at `https://your-site.com/wp-json/wpmcp/mcp`.

Requires WordPress 6.9+ (ships the Abilities API) and PHP 8.0+.

== Frequently Asked Questions ==

= How do I authenticate? =

Use a WordPress Application Password (HTTP Basic auth) or request a JWT from `POST /wp-json/jwt-auth/v1/token`. JWTs are stateful and can be revoked at any time from the admin UI or the `jwt-auth/v1/revoke` route.

= Does it work with WooCommerce? =

Yes. Twenty-eight WooCommerce tools (products, brands, categories, tags, full order CRUD, sales reports) register automatically when WooCommerce is active. Brand tools use the core brands taxonomy that ships in WooCommerce 9.4+.

= Can I limit what AI agents can do? =

Yes. Settings → WordPress MCP provides a master switch, create/update/delete gates, and per-tool toggles. Disabled capabilities never reach a client. Each tool additionally enforces WordPress capability checks for the authenticated user.

= I'm migrating from Automattic/wordpress-mcp — what changes? =

The endpoint moves to `/wp-json/wpmcp/mcp`; tool names are preserved; both auth mechanisms keep working; and `WPMCP_JWT_SECRET_KEY` is now actually honoured. See the README's migration table for details.

== Changelog ==

= 0.1.0 =
* Initial release: 63 tools, 5 resources, 2 prompts on the Abilities API + mcp-adapter stack.
* JWT + Application Password authentication with token management UI.
* Settings-driven gating and experimental REST-CRUD mode.
