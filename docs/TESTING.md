# LlamaHire testing guide

LlamaHire uses a fast WP-CLI contract suite plus a real-browser hiring workflow. Both run against an isolated WordPress site managed by `wp-env`; the CI configuration uses port `8897` so it does not reuse a developer's normal WordPress environment.

For the shorter everyday command reference, including PHPCS, PHP linting,
builds, and releases, see [`../CONTRIBUTING.md`](../CONTRIBUTING.md).

## Local prerequisites

- Node.js 20 or newer.
- PHP 7.4 or newer and Composer 2.
- Docker.

Install the locked dependencies and the test browser:

```sh
npm ci
composer install
npx playwright install chromium
```

## Code-quality checks

Run WordPress VIP PHPCS:

```sh
composer phpcs
```

Run the production build:

```sh
npm run build
```

PHP syntax is checked automatically in CI on every supported PHP version. The
portable local command is listed in
[`../CONTRIBUTING.md`](../CONTRIBUTING.md).

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

For a presentation-ready WordPress Studio site, run this single command from the site root:

```sh
studio wp llamahire fixtures generate --scenario=demo --force
```

The `demo` scenario creates a Northstar Labs careers homepage, polished job content, clean department names, a Media Library brand image, fictional candidates, every application and notification state, resumes, and representative draft, expired, closed, exact-salary, and no-salary roles. `--force` replaces only a previously registered LlamaHire fixture dataset; unrelated site content is preserved.

Available scenarios are `demo`, `small`, `large`, `remote`, `expired`, `closed`, `notification-failures`, and `edge-cases`. Use `--seed=<name>` for stable content, `--jobs=<count>` or `--applications=<count>` for a bounded override, and `--force` to replace only the currently registered fixture dataset.

Each generated site includes organization settings, privacy and Careers pages, a Media Library logo/featured image, departments, complete structured job fields, application statuses and private notes, notification outcomes, and safe sample PDF resumes. A private registry plus per-record ownership markers ensures cleanup removes only LlamaHire-owned fixtures and restores the prior setup/settings options.

Run the fixture lifecycle assertions with:

```sh
npm run test:fixtures
```

The setup command always removes older browser fixtures before creating new ones. The cleanup command removes the test job, candidate record, resume, department, and pattern pages. The isolated site's browser credentials are `admin` / `password`; they are reset only inside this disposable environment and are not connected to a developer's local site credentials.

## Continuous integration

The GitHub Actions workflow runs:

- WordPress VIP PHPCS.
- PHPCompatibilityWP for PHP 7.4 and newer.
- The official WordPress Plugin Check against the staged release package.
- Translation-template regeneration, with a failure if the committed POT is
  stale.
- A production build and installable-package verification.
- PHP syntax checks on PHP 7.4, 8.1, 8.3, and 8.5.
- The complete smoke suite on minimum WordPress 6.5/PHP 7.4, latest WordPress on PHP 7.4 and 8.5, and the WordPress development mirror on PHP 8.5.
- A WP-CLI fixture lifecycle that verifies complete generation, ownership markers, safe resumes, status coverage, option restoration, and preservation of unrelated content.
- The complete Chromium workflow on latest WordPress/PHP 8.3.

The `WordPress/WordPress#master` development mirror tracks WordPress trunk. That forward-looking job is informational and allowed to fail so upstream changes are visible without blocking a release. All declared supported versions are blocking. Browser traces, screenshots, video, and the HTML report are retained when a test fails.

The seven-test browser workflow verifies:

1. An administrator can enter, skip, resume, and complete first-run organization setup.
2. Setup values persist, drive the hiring inbox and privacy notice, become defaults for new jobs, and create a public Careers page from the supplied pattern.
3. Sender identity and plain-text notification templates persist, rendered employer/candidate previews expand, and the candidate-free delivery-test action is available.
4. Careers Hero, Featured Jobs, and Department Landing Page patterns compose without horizontal overflow at 360px; the focused pattern test is also exercised on a representative classic theme.
5. Structured job fields render in the editor, save, and survive reload.
6. Public job facts match `JobPosting` JSON-LD.
7. Configured required, optional, and omitted candidate fields render consistently with server validation.
8. Capable browsers expose a named resume progress bar, busy state, and double-submit protection while the multipart POST remains the no-JavaScript fallback.
9. A simulated connection failure produces a focused alert, preserves the selected local file for retry, and re-enables submission.
10. After a recoverable server-validation redirect, safe text values return once from browser session storage while the resume file input remains empty.
11. A candidate can submit a PDF resume, while a later case-insensitive job/email duplicate receives a neutral success state without another form.
12. The duplicate preserves the original candidate record and does not trigger another notification attempt.
13. A recruiter can update status and private notes.
14. Authorized protected resume download succeeds.
15. CSV export contains the application and neutralizes formula-like content.
16. A board administrator can enable job-board mode and receives automatically created Submit a Job and My Jobs pages.
17. An administrator-approved Employer can upload a Media Library-backed company logo, submit a pending listing, edit only that listing, and see it become published after board moderation.
18. The employer can preview, close, and deliberately move an owned listing to the trash, while repeat submissions prefill the latest owned company and application-routing details.
19. Internal job-board applications clearly identify the receiving company before submission.
20. Application status changes appear in privacy-safe application history and the author-scoped Activity screen without exposing candidate email addresses.
