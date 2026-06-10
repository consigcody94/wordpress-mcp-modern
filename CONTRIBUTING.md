# Contributing

Thanks for your interest in improving WordPress MCP (Modern)! Issues and pull
requests are welcome.

## Development setup

You need Node.js (for `@wordpress/env`) and Docker. No local PHP or Composer
required — both run in containers.

```bash
git clone https://github.com/consigcody94/wordpress-mcp-modern.git
cd wordpress-mcp-modern

# Install PHP dependencies via the Composer Docker image
docker run --rm -v "$PWD":/app -w /app composer:2 install

# Spin up WordPress + a tests environment
npx @wordpress/env start
```

The plugin is mounted into the environment automatically (see `.wp-env.json`).
The site runs at `http://localhost:8888` (admin: `admin` / `password`), and the
MCP endpoint is `http://localhost:8888/wp-json/wpmcp/mcp`.

## Running the tests

```bash
npx @wordpress/env run tests-cli \
  --env-cwd=wp-content/plugins/wordpress-mcp-modern \
  vendor/bin/phpunit
```

The suite runs against a real WordPress install. CI runs the same suite (plus a
PHP syntax lint on the minimum and latest supported PHP versions) on every push
and pull request.

## Guidelines

- **Code style**: WordPress PHP coding standards — tabs for indentation,
  snake_case functions, Yoda conditions where the existing code uses them.
  Match the style of the file you're editing.
- **Every ability group change needs tests.** Each group has a corresponding
  `tests/*Test.php` covering registration and an execution round-trip.
- **Tool names are a compatibility surface.** Legacy `wordpress-mcp` tool names
  (`wp_posts_search`, `wc_get_product`, …) must keep working; new tools should
  follow the same naming conventions.
- **Two security layers stay intact.** Anything callable must pass both the
  transport permission and a per-ability `permission_callback`.
- **Update the docs.** User-visible changes belong in `README.md`,
  `readme.txt` (if user-facing), and `CHANGELOG.md` under *Unreleased*.

## Releasing (maintainers)

1. Update the version in `wordpress-mcp-modern.php` (header + constant),
   `readme.txt` (stable tag + changelog), and move the *Unreleased* section of
   `CHANGELOG.md` to the new version.
2. Tag the release: `git tag v0.x.y && git push --tags`.
3. The **Release** workflow builds the distributable zip (with bundled
   `vendor/`) and attaches it to the GitHub Release.
