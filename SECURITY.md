# Security Policy

## Supported versions

Only the latest release receives security fixes.

## Reporting a vulnerability

Please **do not** open a public issue for security vulnerabilities.

Instead, report it privately via
[GitHub Security Advisories](https://github.com/consigcody94/wordpress-mcp-modern/security/advisories/new).
You should receive a response within a few days.

## Scope notes

This plugin intentionally exposes WordPress capabilities to authenticated AI
agents. The following are by design and not vulnerabilities on their own:

- An authenticated user (JWT or Application Password) can do anything their
  WordPress role already allows via the REST API.
- Site administrators can enable destructive tools (delete posts/media/users)
  from the settings page.

Reports we're especially interested in: authentication bypasses, privilege
escalation beyond the authenticated user's role, JWT validation/revocation
flaws, and any way for a disabled tool to remain reachable.
