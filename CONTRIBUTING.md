# Developing LlamaHire

This is the short reference for setting up the repository, running checks, and
shipping the free plugin. More detail is available in
[`docs/TESTING.md`](docs/TESTING.md) and
[`docs/RELEASING.md`](docs/RELEASING.md).

## Requirements

- Node.js 20 or newer.
- PHP 7.4 or newer.
- Composer 2.
- WP-CLI for translation-template generation.
- Docker for the WordPress and browser test suites.

Install the locked dependencies:

```sh
npm ci
composer install
npx playwright install chromium
```

## Everyday checks

Run the WordPress VIP coding standards:

```sh
composer phpcs
```

Check compatibility with PHP 7.4 and newer:

```sh
composer phpcompatibility
```

Scan one PHP file or show only errors:

```sh
vendor/bin/phpcs includes/class-applications.php
vendor/bin/phpcs --warning-severity=0
```

Include the sniff codes when investigating or documenting an intentional
exception:

```sh
vendor/bin/phpcs -s
```

Check PHP syntax:

```sh
find . -name '*.php' \
  -not -path './node_modules/*' \
  -not -path './vendor/*' \
  -not -path './dist/*' \
  -print0 | xargs -0 -n1 php -l
```

Build the production assets:

```sh
npm run build
```

Validate the agent-facing visual design contract after changing UI patterns or
`DESIGN.md`:

```sh
npm run design:lint
```

Regenerate the translation template after changing translatable copy:

```sh
npm run i18n:make-pot
```

Commit the resulting `languages/llamahire.pot`. CI regenerates it and fails if
the committed template is stale. WordPress.org manages community translations
from this source template after the plugin is published.

## WordPress tests

Start the disposable WordPress environment:

```sh
npm run env:start
```

Run the fast contract tests:

```sh
npm run test:smoke
```

Run the fixture lifecycle tests:

```sh
npm run test:fixtures
```

Run the browser workflow:

```sh
npm run test:e2e:setup
npm run test:e2e
npm run test:e2e:cleanup
```

Stop the environment when finished:

```sh
npm run env:stop
```

See [`docs/TESTING.md`](docs/TESTING.md) for fixture scenarios, test coverage,
and troubleshooting context.

## Test the release package

Build and verify the same package shape used by CI:

```sh
npm ci
npm run build
bash scripts/build-release.sh
unzip -t dist/llamahire-*.zip
```

The generated ZIP and checksum are written to `dist/`.
CI also runs the official WordPress Plugin Check action against the staged
`dist/llamahire` directory, so it inspects what users will actually install.

## Publish a release

Before publishing:

1. Update the plugin header `Version` in `llamahire.php`.
2. Update `LLAMAHIRE_VERSION` in `llamahire.php`.
3. Update `Stable tag` and the changelog in `readme.txt`.
4. Regenerate `languages/llamahire.pot`.
5. Push the release commit and make sure CI passes.
6. Publish a non-prerelease GitHub release with a matching tag, such as
   `0.2.0` or `v0.2.0`.

The release workflow validates the versions, rebuilds and verifies the package,
attaches the ZIP and checksum to GitHub, and deploys to WordPress.org when that
integration is enabled. See [`docs/RELEASING.md`](docs/RELEASING.md) for the
one-time WordPress.org setup and safety switch.
