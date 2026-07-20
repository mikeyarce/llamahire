# LlamaHire testing guide

LlamaHire uses a fast WP-CLI contract suite plus a real-browser hiring workflow. Both run against an isolated WordPress site managed by `wp-env`; the CI configuration uses port `8897` so it does not reuse a developer's normal WordPress environment.

## Local prerequisites

- Node.js 20 or newer.
- Docker.

Install the locked dependencies and the test browser:

```sh
npm ci
npx playwright install chromium
```

## Run the suites

Start the isolated site and run the contract checks:

```sh
npm run env:start
npm run test:smoke
```

Run the browser workflow with deterministic disposable data:

```sh
npm run test:e2e:setup
npm run test:e2e
npm run test:e2e:cleanup
```

Stop the environment when finished:

```sh
npm run env:stop
```

## Populate a manual test site

The development checkout provides deterministic WP-CLI fixture commands. The implementation lives in `tools/`, loads only under WP-CLI, refuses production environments, and is intentionally excluded from release ZIPs.

For the bundled `wp-env` site:

```sh
npm run env:start
npm run fixtures:generate
npm run fixtures:status
npm run fixtures:cleanup
```

On another local, development, or staging WordPress installation where this repository checkout is the active plugin:

```sh
wp llamahire fixtures generate --scenario=small
wp llamahire fixtures status
wp llamahire fixtures cleanup --yes
```

Available scenarios are `small`, `large`, `remote`, `expired`, `closed`, `notification-failures`, and `edge-cases`. Use `--seed=<name>` for stable content, `--jobs=<count>` or `--applications=<count>` for a bounded override, and `--force` to replace only the currently registered fixture dataset.

Each generated site includes organization settings, privacy and Careers pages, a Media Library logo/featured image, departments, complete structured job fields, application statuses and private notes, notification outcomes, and safe sample PDF resumes. A private registry plus per-record ownership markers ensures cleanup removes only LlamaHire-owned fixtures and restores the prior setup/settings options.

Run the fixture lifecycle assertions with:

```sh
npm run test:fixtures
```

The setup command always removes older browser fixtures before creating new ones. The cleanup command removes the test job, candidate record, and resume. The isolated site's browser credentials are `admin` / `password`; they are reset only inside this disposable environment and are not connected to a developer's local site credentials.

## Continuous integration

The GitHub Actions workflow runs:

- PHP syntax checks on PHP 7.4, 8.1, 8.3, and 8.5.
- The 92-check smoke suite on minimum WordPress 6.5/PHP 7.4, latest WordPress on PHP 7.4 and 8.5, and the WordPress development mirror on PHP 8.5.
- A WP-CLI fixture lifecycle that verifies complete generation, ownership markers, safe resumes, status coverage, option restoration, and preservation of unrelated content.
- The complete Chromium workflow on latest WordPress/PHP 8.3.

The `WordPress/WordPress#master` development mirror tracks WordPress trunk. That forward-looking job is informational and allowed to fail so upstream changes are visible without blocking a release. All declared supported versions are blocking. Browser traces, screenshots, video, and the HTML report are retained when a test fails.

The four-stage browser workflow verifies:

1. An administrator can enter, skip, resume, and complete first-run organization setup.
2. Setup values persist, drive the hiring inbox and privacy notice, become defaults for new jobs, and create a public Careers page from the supplied pattern.
3. Structured job fields render in the editor, save, and survive reload.
4. Public job facts match `JobPosting` JSON-LD.
5. A candidate can submit a PDF resume.
6. A recruiter can update status and private notes.
7. Authorized protected resume download succeeds.
8. CSV export contains the application and neutralizes formula-like content.
