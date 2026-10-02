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

Validate the root visual design contract:

```sh
npm run design:lint
```

Check both production and development dependency trees:

```sh
npm audit --omit=dev
npm audit
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

Verify network-wide uninstall behavior in its dedicated multisite environment:

```sh
npm run env:multisite:start
npm run test:uninstall:multisite
npm run env:multisite:stop
```

Run the browser workflow with deterministic disposable data:

```sh
npm run test:e2e:setup
npm run test:e2e
npm run test:e2e:cleanup
```

For a quicker core-flow check while developing, run the tagged critical path:

```sh
npm run test:e2e:setup
npm run test:e2e:critical
npm run test:e2e:cleanup
```

The scenario names carry product-area tags to make failures and reports easy to
scan. Until the current serial workflow is split into independent fixtures, use
the supported `test:e2e:critical` command instead of assuming every single tag
can run without its preceding setup scenario. The tags and the planned fast,
independent scenario coverage are listed in
[`E2E-SCENARIOS.md`](E2E-SCENARIOS.md).

The independent cached-form and Hiring pagination regressions manage and clean
their own disposable fixtures, including 101 candidates. Run them with:

```sh
npm run test:e2e -- tests/e2e/review-fixes.spec.js
```

The additional user journeys also create fresh fixtures before each test and
restore settings, remove owned records and resumes, and deactivate their test
transport afterward:

```sh
npm run env:start
npm run test:e2e:flows
```

These cover employer registration and validation, verification and manual or
automatic approval, admin job creation and publishing, moderation outcomes,
closing/reopening, renewal and relisting, unavailable jobs, external application
destinations, every candidate stage and rejection cancellation, cross-employer
access denial and scoped exports, candidate erasure, notification failure/retry, settings taking
effect on public forms, both anti-spam providers, and actual application/job
submission with JavaScript disabled. All run in the complete CI browser suite.

`flow-fixtures.php` refuses any site other than `http://localhost:8897`. Its
temporary transport intercepts mail and anti-spam verification, so these tests
require neither real inboxes nor provider keys. Verification links are captured
privately by the fixture helper; candidate mail bodies are never recorded. The
shared-IP employer registration limit is disabled only while these fixtures
are active; rate-limit boundaries remain covered by the PHP suite. Mail retry
proves WordPress acceptance and retry state, not delivery to an external inbox.
Use `npm run test:e2e:cleanup` to recover fixtures after an interrupted run.
Keep one browser worker because settings are shared across the disposable site.

The cached-form and pagination regressions cover distinct applicants sharing
cached form HTML, safe retries,
full stage totals, keyboard access to older candidates, and retained review context at
desktop and narrow widths. The smoke suite also runs
`tests/review-regressions.php` for legacy submission keys, VIP attachment path
verification, replacement-service lifecycle binding, employer REST restrictions
on featured status and listing expiration, and pagination boundaries.

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

For a complete current-state QA workspace, use the state matrix:

```sh
npm run fixtures:matrix
# Or, against the Studio site:
studio wp llamahire fixtures generate --scenario=state-matrix --force
```

The state matrix creates descriptive records for every supported application workflow stage (`new`, `reviewing`, `interviewing`, `offer`, `hired`, and `rejected`), every notification outcome (`pending`, `sent`, `partial`, and `failed`), candidates with and without each optional material, zero/one/multiple private notes, and protected PDF plus DOCX resumes when ZIP support is available. It also creates a Hiring Manager, employers awaiting email verification and operator approval, two approved employers with separate ownership scopes, and jobs covering draft, pending moderation, open, closing soon, listing expiration soon, deadline-expired, listing-expired, manually closed, featured, exact-salary, no-salary, minimal-facts, all five pay units, on-site, hybrid, remote, internal application, external URL, and external email states. The shared password for the generated state-matrix users is `llamahire-matrix`; usernames include the stable seed suffix and are printed by the command and visible through `wp user list`.

This is a meaningful product-state matrix rather than every mechanically possible database combination. Invalid or contradictory combinations belong in automated validation tests and are not inserted into a manual QA site as if they were supported records.

To create a repeatable approved Employer account, its public pages, a linked listing-rules page, and draft/expiring/expired jobs for portal testing, run:

```sh
studio wp llamahire fixtures employer --force
```

The defaults are `llamahire-employer` / `llamahire-demo`. Override them with `--username=<login>` and `--password=<password>`. Remove only this account and its owned records, while restoring the prior LlamaHire settings, with `studio wp llamahire fixtures employer --cleanup`.

The `demo` scenario creates a Northstar Labs careers homepage, polished job content, clean department names, a Media Library brand image, fictional candidates, every application and notification state, resumes, and representative draft, expired, closed, exact-salary, and no-salary roles. `--force` replaces only a previously registered LlamaHire fixture dataset; unrelated site content is preserved.

Available scenarios are `demo`, `small`, `large`, `remote`, `expired`, `closed`, `notification-failures`, `edge-cases`, and `state-matrix`. Use `--seed=<name>` for stable content, `--jobs=<count>` or `--applications=<count>` for a bounded override, and `--force` to replace only the currently registered fixture dataset. Do not override the state-matrix counts when complete coverage is required.

Each generated site includes organization settings, privacy and Careers pages, a Media Library logo/featured image, departments, complete structured job fields, application statuses and private notes, notification outcomes, and safe sample PDF resumes. A private registry plus per-record ownership markers ensures cleanup removes only LlamaHire-owned fixtures and restores the prior setup/settings options. Smoke coverage verifies that full-uninstall helpers retain unhandled custom tokens, remove supported storage tokens, preserve legacy DOC delivery metadata, and delete taxonomy rows even when the plugin's runtime registrations are inactive. It also exercises public job-REST recipient redaction, concurrent rate-counter contention, address changes during cached geocoding, activation-time taxonomy migration retries, the fixed CSV export column contract including byte-order-mark formula neutralization, dashboard email-attention link destinations, and numeric parity between dashboard open/closing-soon counts and their filtered job-list views in board-wide and author scopes.

Run the fixture lifecycle assertions with:

```sh
npm run test:fixtures
```

The setup command always removes older browser fixtures before creating new ones. The cleanup command removes the test jobs, candidate records, resumes, department, job type, registration page, and pattern pages. Recruiter coverage verifies multi-selection, destination/count confirmation, keyboard submission, retained query state, permission-safe bulk status changes, and privacy-safe audit history. Employer-flow coverage verifies the public registration fields and policy agreement, configurable approval and listing-policy controls, automatic page creation, frontend edit persistence after failed preview validation, adaptive physical/remote location entry, method-specific application routing, a real preview, moderation submission, explicit operator approval, the submitted listing’s separate public expiration, and clean-draft duplication. Smoke coverage also verifies the default outside-webroot resume driver, the VIP ACL driver's explicit protection health state, the separate employer-account and job-listing queues, all three moderation outcomes, one reminder per saved expiration, and the expired-to-relist action state. The isolated site's browser credentials are `admin` / `password`; they are reset only inside this disposable environment and are not connected to a developer's local site credentials.

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
- A two-site multisite full-uninstall regression on latest WordPress/PHP 8.3.
- A WP-CLI fixture lifecycle that verifies complete generation, ownership markers, safe resumes, status coverage, option restoration, and preservation of unrelated content.
- The complete Chromium workflow on latest WordPress/PHP 8.3.

The `WordPress/WordPress#master` development mirror tracks WordPress trunk. That forward-looking job is informational and allowed to fail so upstream changes are visible without blocking a release. All declared supported versions are blocking. Browser traces, screenshots, video, and the HTML report are retained when a test fails.

The browser suites verify:

1. An administrator can enter, skip, resume, and complete first-run organization setup.
2. Setup values persist, drive the hiring inbox and privacy notice, become defaults for new jobs, and create a public Careers page from the supplied pattern.
3. Sender identity and plain-text notification templates persist, rendered employer/candidate previews expand, and the candidate-free delivery-test action is available.
4. Careers Hero, Featured Jobs, and Department Landing Page patterns compose without horizontal overflow at 360px; the focused pattern test is also exercised on a representative classic theme.
5. Structured job fields render in the editor, save, and survive reload.
6. Public job facts match `JobPosting` JSON-LD.
7. Configured required, optional, and omitted candidate fields render consistently with server validation.
8. Capable browsers expose a named resume progress bar, busy state, and double-submit protection while the multipart POST remains the no-JavaScript fallback.
9. A simulated connection failure produces a focused alert, preserves the selected local file for retry, and re-enables submission.
10. After a recoverable server-validation response, safe text values remain in the live form, the resume input is cleared, focus moves to the error, and no candidate values are written to browser storage.
11. A candidate can submit a PDF resume, while a later case-insensitive job/email duplicate receives the exact generic success state and consumes the same rate limit without revealing the prior application.
12. The duplicate preserves the original candidate record and does not trigger another notification attempt.
13. A recruiter can update status and private notes.
14. Authorized protected resume download succeeds.
15. CSV export contains the application and neutralizes formula-like content.
16. A board administrator can enable job-board mode and receives automatically created Submit a Job, My Jobs, and Account pages.
17. An administrator-approved Employer can save a title-only draft, resume it, choose a physical or remote location path, complete the structured job and company fields, preview the result, and submit it for board moderation. Operators can follow separate dashboard queues for employer accounts and job listings, then approve a pending listing with an explicit moderation action.
18. The employer can search and filter only owned listings, navigate a server-paginated My Jobs result set, update contact and company details on the frontend Account page, reach password recovery, sign out, see an awaiting-review listing become published, renew it near expiration, duplicate it into a clean draft, preview or close it, and deliberately move it to the trash; repeat submissions prefill the latest owned company and application-routing details. For jobs using LlamaHire applications, My Jobs links to an ownership-scoped frontend candidate list and review surface. Employer sign-in and legacy application links stay in the frontend portal, while status, notes, files, and activity remain protected by capability and ownership checks.
19. Internal job-board applications clearly identify the receiving company before submission.
20. Application status changes appear in privacy-safe application history and the author-scoped Activity screen without exposing candidate email addresses.
21. Optional Turnstile or reCAPTCHA protection renders only after complete provider keys are configured, covers employer registration and candidate applications independently, consumes local attempt limits before contacting the provider, and blocks failed server-side verification.

## Launch theme compatibility

Run the dedicated matrix against the disposable site:

```sh
npm run env:start
npm run test:e2e:themes
```

The opt-in suite installs Twenty Twenty-Five, Astra, GeneratePress, Hello
Elementor, and Elementor. It uses the existing browser fixture setup/cleanup;
run it separately from other browser suites because themes and settings are
shared. It restores the original theme, Elementor activation state, and
Twenty Twenty-Five user styles. Installed packages remain in the test site.
It refuses a base URL other than the isolated site.

Six configurations cover Twenty Twenty-Five default/Midnight, Astra,
GeneratePress, and Hello alone/with Elementor active. Checks cover directory,
department, careers patterns, single-job/application, and employer registration
at 1440px and 360px, duplicate application forms, a 600px job content
column, and contrast of metadata, help text, required labels, and submit buttons.
It also checks search empty states, rendered success/error notices, and
authenticated My Jobs, Account, and Submit a Job pages at both widths.
Classic archives retain the native theme loop; the Careers page provides the
complete searchable directory. Screenshots and theme versions are attached to `playwright-theme-report/`
for visual review.
Elementor activation coverage does not certify Elementor-authored pages or Pro
Theme Builder templates.

Before publishing a tested-theme claim, record installed versions and review
screenshots, keyboard focus, text/control contrast, 200% and 320% zoom, actual form
submission/closed states and a real sidebar
configuration. Do not treat passing overflow checks as visual approval.

The launch support policy and saved-pattern limitations are documented in
[THEME-SUPPORT.md](THEME-SUPPORT.md).
