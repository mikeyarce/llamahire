# LlamaHire agent guide

This file applies to the LlamaHire plugin repository. The parent WordPress
Studio instructions at `../../../AGENTS.md` also apply.

## Development environment

LlamaHire is developed inside a **WordPress Studio** site. The plugin root is:

```text
wp-content/plugins/llamahire
```

The Studio site root is three levels above this directory (`../../..`). Use the
Studio site for normal development, manual QA, screenshots, and inspecting real
WordPress behavior.

- Verify the CLI first with `studio --version`.
- Run `studio status` from the site root to discover the current URL,
  credentials, PHP version, and WordPress version. Do not hardcode the Studio
  port; it may change.
- Start a stopped site with `studio start --skip-browser`.
- Run every WP-CLI command for this site through `studio wp`, from the site
  root. Do not use a bare `wp` command against the Studio site.
- Studio uses SQLite through WordPress's database integration. Use WordPress
  APIs and portable `$wpdb` queries; do not depend on MySQL-only features such
  as `FULLTEXT`, or on `DB_HOST`/`DB_NAME`.
- Never edit WordPress core, `wp-content/db.php`, or Studio's SQLite
  must-use plugin.

The repository also defines an isolated Docker-based `wp-env` site on port
8897. That environment is for automated smoke, fixture, and Playwright tests;
it is separate from the long-lived Studio site. The `npm run env:*` and
`npm run test:*` scripts deliberately target `.wp-env.ci.json`, not Studio.

To put deterministic presentation data into the Studio site, run this from the
site root:

```sh
studio wp llamahire fixtures generate --scenario=demo --force
```

This is a state-changing command. Use it only when demo data is wanted.
`--force` replaces the registered LlamaHire fixture dataset while preserving
unrelated content. See `docs/TESTING.md` for scenarios and cleanup behavior.

## What this repository contains

LlamaHire is a WordPress 6.5+ / PHP 7.4+ job-board and applicant-tracking
plugin. `llamahire.php` defines versions and boots the plugin.

- `includes/` contains the PHP runtime. `class-plugin.php` loads classes,
  registers services, and initializes features on `init`.
- `includes/contracts/` and `includes/class-service-ids.php` are the extension
  boundary. Concrete implementations live in `includes/services/`.
- `includes/class-jobs.php` owns the `llamahire_job` post type, department
  taxonomy, structured job metadata, and editor integration.
- `includes/class-applications.php`, the repository/query services, and
  lifecycle services own candidate submissions, the custom applications table,
  notification state, retention, and private resumes.
- `includes/class-migrations.php` owns forward-only, idempotent schema changes.
  `includes/class-capabilities.php` owns granular permissions.
- `includes/class-blocks.php` renders the dynamic blocks described by
  `blocks/*/block.json`. `patterns/` provides editable block patterns.
- `includes/class-theme-support.php` and `templates/` provide block-theme and
  classic-theme integration.
- `assets/js/` and `assets/css/` are mostly direct runtime assets.
- `src/admin-applications.js` and its SCSS are bundled by WordPress Scripts
  into committed runtime files under `build/`.
- `tools/` contains development-only WP-CLI fixtures and is intentionally
  excluded from releases.
- `tests/smoke.php` is the broad WP-CLI contract suite.
  `tests/e2e/` contains the Playwright hiring workflow.
- `docs/PUBLIC-API.md`, `docs/BLOCKS.md`, `docs/TESTING.md`,
  `docs/VALIDATION.md`, and `docs/RELEASING.md` are the main design and
  operational references. Dated material under `docs/audits/` is evidence, not
  the primary specification.
- `DESIGN.md` is the canonical visual-design contract for both WordPress admin
  and theme-aware public interfaces. Read it before creating or changing UI,
  reuse its semantic tokens and component rules, and update it when introducing
  a genuinely reusable pattern.

The Free plugin is also the platform for Pro and third-party extensions.
Documented contracts, service IDs, hooks, capability constants, and the
`llamahire_register_services` / `llamahire_ready` lifecycle are public.
Concrete service classes, table layout, resume paths, admin URLs, selectors,
and renderer internals are not public API. Consult `docs/PUBLIC-API.md` before
changing an extension boundary.

## Editing conventions

- Follow WordPress VIP coding standards and the repository's tab-based PHP
  style. Keep PHP compatible with 7.4.
- Sanitize request data, verify nonces, check a specific capability, and escape
  at output. Candidate-data authorization must use LlamaHire's granular
  capabilities and ownership helpers, not role names, `manage_options`, or
  menu visibility.
- Use prepared SQL for dynamic values. Prefer WordPress options, metadata,
  post, taxonomy, REST, and filesystem APIs where they fit.
- Candidate names, email addresses, notes, resumes, private storage tokens,
  and mail errors are sensitive. Do not expose them in public APIs, logs,
  fixtures, URLs, screenshots, or error messages. Keep resume access behind
  ownership/capability and nonce checks.
- Preserve the no-JavaScript baseline for public forms and job discovery.
  JavaScript should progressively enhance the server-rendered WordPress flow.
- Keep visible job facts and `JobPosting` JSON-LD derived from the same saved
  model. A change to salary, location, workplace, deadline, organization, or
  availability usually needs both visible-output and schema coverage.
- Dynamic block behavior belongs in PHP render callbacks. Keep `block.json`
  attributes, editor previews in `assets/js/blocks.js`, server rendering, block
  context, patterns, and `docs/BLOCKS.md` aligned.
- Theme styles should remain low-specificity and inherit WordPress/theme
  tokens. Verify responsive behavior with both a block theme and a
  representative classic theme when changing public markup or CSS.
- When adding a schema migration, append a forward-only, repeatable migration,
  increment `LLAMAHIRE_SCHEMA_VERSION`, preserve existing records, and extend
  smoke coverage. Do not rewrite an already-released migration as though it
  never ran.
- When changing capabilities, update the capability version and cover both
  allowed and denied users.
- When changing translatable strings, regenerate and commit
  `languages/llamahire.pot`.
- When changing the bundled applications UI, run `npm run build` and include
  the resulting `build/` files. Most files under `assets/` are not generated
  and should be edited directly.
- When changing `DESIGN.md`, run `npm run design:lint`. Treat the document as
  the agent-facing design contract and runtime CSS as its executable
  implementation; keep both aligned without forcing public UI to override the
  active theme.
- Do not edit generated dependencies in `node_modules/` or `vendor/`, packaged
  output in `dist/`, or transient Playwright output.

## Setup and commands

Install locked dependencies:

```sh
npm ci
composer install
npx playwright install chromium
```

Useful focused checks:

```sh
npm run build
composer phpcs
composer phpcompatibility
node --check assets/js/blocks.js
vendor/bin/phpcs includes/class-applications.php
```

Automated WordPress checks use the disposable environment:

```sh
npm run env:start
npm run test:smoke
npm run test:fixtures
npm run test:e2e:setup
npm run test:e2e
npm run test:e2e:cleanup
npm run env:stop
```

Do not assume the whole sequence is necessary for every change. Run the
smallest relevant checks first, then expand based on risk:

- PHP-only change: syntax/PHPCS for touched files, then smoke tests when
  WordPress behavior or a contract changed.
- Direct JavaScript change: `node --check` for touched files and the relevant
  browser flow.
- Bundled admin-app change: `npm run build`, then relevant browser coverage.
- Block, template, CSS, setup, or admin-workspace change: verify in the running
  Studio site at desktop and narrow widths; add Playwright coverage for durable
  behavior.
- Persistence, privacy, capability, submission, migration, notification, or
  resume change: run PHPCS plus smoke tests and the relevant end-to-end path.

The isolated browser account is `admin` / `password`; those credentials are
only for `wp-env`. Discover Studio credentials with `studio status`.

## Release-sensitive files

Runtime versions must stay synchronized when releasing:

- plugin header `Version` in `llamahire.php`
- `LLAMAHIRE_VERSION` in `llamahire.php`
- `Stable tag` and changelog in `readme.txt`

Build a release-equivalent package with:

```sh
npm run build
bash scripts/build-release.sh
unzip -t dist/llamahire-*.zip
```

The release ZIP intentionally includes runtime assets, blocks, build output,
PHP, patterns, templates, and languages while excluding development tools,
tests, scripts, docs, and dependency metadata. See `docs/RELEASING.md` before
changing packaging or publishing behavior.

## Working-tree discipline

This repository may contain substantial in-progress work. Inspect
`git status --short` before editing, preserve unrelated modifications, and do
not reset, clean, overwrite, or reformat user changes. Update documentation and
tests when behavior or a documented contract changes, and report exactly which
checks were run.
