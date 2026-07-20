# LlamaHire validation record

Last validated: July 20, 2026
Plugin version: 0.1.0
Environment: local WordPress 7.0.2, PHP 8.5.5, WP-CLI, Chrome

## Result

The Phase 1 foundation activates and completes its core public application path. The versioned Free public API, duplicate-submission protection, observable notification workflow, and structured Google Jobs model pass their contract suite and live HTTP validation.

## Automated smoke checks

Run from the WordPress root:

```sh
wp eval-file wp-content/plugins/llamahire/tests/smoke.php
```

The disposable test creates and removes its own records. Eighty-nine checks now pass, covering:

- Job post type, department taxonomy, blocks, publication, availability, directory, and form rendering.
- Applications table creation, repository persistence, retrieval, status changes, and private notes.
- Public API version, lifecycle timing, contract conformance, service availability, and registry immutability.
- Editor REST registration and organization-default normalization.
- Block-editor REST persistence and synchronization of queryable job metadata.
- JobPosting generation for physical, hybrid, and fully remote jobs.
- Structured addresses, eligible remote countries, organization overrides, stable identifiers, salary ranges, and pay units.
- Protection against incorrectly marking hybrid jobs as fully remote.
- Visible salary/pay-period parity with structured data.
- Deadline/expiry parity, exact salary, omitted salary, incomplete location, closed job, and expired job schema behavior.
- Declared schema and capability maintenance versions.
- Administrator hiring grants and default subscriber denial.
- Dedicated WordPress meta-cap mapping for jobs and department terms.
- Idempotent schema replay while an application exists.
- Preservation of application data during migration replay.
- Retirement of the legacy database-version option.
- Protection against older plugin code downgrading a newer database schema.
- Application-query and resume-storage contract conformance.
- Paginated application filtering and bounded export iteration.
- Private resume-path redaction from public application records.
- Writable private-storage health.
- A unique, browser-generated submission key and database-enforced idempotent application creation.
- Failed, partial, and successful notification attempts without exposing mail error messages or candidate content.
- Missing-channel retries that preserve a previously successful delivery.
- Administrator permission to retry missing notifications, with subscriber denial.
- Candidate form help/privacy associations and assertive application errors.
- Published-job sitemap inclusion with accurate modification time, historical closed-job URL retention, and deleted-job removal.
- Registration and composition of standalone Job Search and Job Filters blocks.
- Preserved URL query state, normalized employment/location filters, result counts, clear actions, recoverable empty states, and paginated job results.

A full deactivate/reactivate cycle also completed successfully. Existing schema and capability versions remained current.

## HTTP workflow validation

A temporary published job was requested through the local WordPress HTTP server. Validation confirmed:

- The job title rendered on its public page.
- The application form was appended automatically.
- A valid per-job nonce was present.
- `JobPosting` JSON-LD was present.
- A multipart application with a PDF resume submitted successfully.
- The browser response showed the success confirmation.
- The candidate record was stored with the `new` status.
- The original resume filename was retained for authorized downloads.
- The resume file was readable by the application and stored outside `ABSPATH`.
- Temporary candidate, resume, and job data were removed afterward.

The same HTTP workflow was rerun after application writes, notifications, resume lookup, admin updates, and schema generation moved behind the public service contracts. The job page, form, JobPosting entity, resume upload, persistence, and success response all remained functional; the temporary data was removed.

It was rerun again after resume storage extraction. The upload succeeded, the service reported the resume available, the public application record exposed only `has_resume`, and the underlying path remained private. Temporary data was removed.

The exact same form payload was then submitted twice with one UUID submission key. Both requests returned the normal success outcome, while the database contained exactly one application and recorded exactly one notification attempt. The temporary application and job were removed afterward.

A complete hybrid job was requested from the running local site after the Google Jobs model upgrade. The public page visibly rendered the hiring organization, full postal address, salary range and yearly pay period, deadline, and stable job reference. Its JSON-LD contained the matching `JobPosting`, `addressCountry`, `unitText`, identifier, and `directApply` values. The temporary job was removed afterward.

The authenticated block editor was then exercised in Chrome. Validation confirmed:

- All five LlamaHire sidebar panels rendered without a plugin or console error.
- A new job showed focused readiness guidance and received a stable reference automatically.
- Hybrid address, compensation, and organization fields saved and survived a full editor reload.
- Switching to fully remote replaced the physical-address controls with eligible-country controls.
- Remote readiness changed from a missing-country warning to complete after entering `US, CA`.
- The job published successfully from WordPress.
- Its public page visibly showed the organization, `Remote — US, CA`, salary range/pay period, and stable reference.
- Its JSON-LD matched those values and emitted `TELECOMMUTE`, both applicant countries, `CAD`, the range, `YEAR`, the stable identifier, and `directApply: true`.
- The temporary job was removed afterward.

The authenticated recruiter workflow was also exercised against disposable application data. Validation confirmed:

- The application list and candidate detail view showed the expected job, contact details, status, and notification state.
- Changing an application to Reviewing and adding a private note survived a full redirect and reload.
- A real multipart submission stored its PDF resume in protected fallback storage when the host's preferred outside-root directory was unavailable.
- An authorized administrator could download that resume through the protected endpoint.
- CSV export included the tested applications and neutralized a formula-like cover-letter value.
- The disposable applications, jobs, and resume files were removed afterward.

These workflows are now encoded in a repeatable `wp-env` and Playwright integration harness. A clean isolated WordPress 7.0.2 environment passed all 89 smoke checks and all four browser tests in one run. The browser suite covers first-run organization/privacy setup, composed Careers-page search/filter behavior, editor authoring, candidate application, and recruiter review before removing its own fixtures. CI retains failure traces, screenshots, video, and an HTML report. See [TESTING.md](TESTING.md).

## Focused accessibility review

The July 20 keyboard and screen-reader pass reviewed setup, job authoring, candidate application, the applications list, and recruiter review. It confirmed keyboard activation in the Playwright workflow and fixed progress semantics, unique control IDs, help associations, error/status announcements, filter/table semantics, submenu order, and the narrow recruiter layout. Evidence and limitations are recorded in [the accessibility review](audits/2026-07-20-accessibility-review/REVIEW.md).

## Hosted supported-version matrix

Draft pull request #1 ran the complete CI workflow for both its branch push and pull-request event. Both runs passed packaging, PHP 7.4/8.1/8.3/8.5 syntax, WordPress 6.5 on PHP 7.4, current WordPress on PHP 7.4 and 8.5, WordPress development on PHP 8.5, the browser hiring workflow, and the deterministic WP-CLI fixture lifecycle. The hosted run IDs were `29774431177` and `29774482010`.

The smoke suite also verifies that an open published job appears in WordPress XML sitemaps with the expected modification time; closing it preserves the useful historical URL while removing active `JobPosting` markup and application submission; reopening restores active behavior; and permanent deletion removes the URL from the sitemap.

## Test-site fixture validation

The development-only `wp llamahire fixtures` command group was exercised against the isolated site. Validation confirmed:

- All seven named scenarios (`small`, `large`, `remote`, `expired`, `closed`, `notification-failures`, and `edge-cases`) generate and can replace one another with `--force`.
- The lifecycle suite created structured jobs, every application status, notification outcomes, private notes, departments, privacy/Careers pages, a Media Library image, and safe PDF resumes.
- Edge fixtures include draft, expired, manually closed, exact-salary, and no-salary jobs.
- Cleanup required both registry membership and record-level ownership proof, restored prior settings/setup options, and preserved an unrelated WordPress post.
- The full large scenario created 60 jobs and 1,000 applications in about nine seconds locally and removed all owned data in about three seconds.
- The rebuilt release ZIP excludes `tools/`, tests, scripts, package metadata, and hidden development files.

The fixture lifecycle now runs as a dedicated GitHub Actions job.

Backend benchmark evidence and remaining database release gates are recorded in [PERFORMANCE.md](PERFORMANCE.md).

## Defect found and resolved

Email body strings used positional `sprintf()` placeholders inside interpolated PHP strings. PHP interpreted the `$s` portions as variables, causing a PHP 8 `ValueError` after the application had been stored. The dollar signs are now escaped in the source strings, preserving the runtime `%1$s` placeholders.

The resume-storage health check called `realpath()` on a host path blocked by `open_basedir`. PHP emitted a warning that could leak into an authorized download response even though storage correctly failed closed. Filesystem probes are now warning-suppressed at that host boundary, and the protected uploads fallback remains available.

## Still requiring release validation

These checks need purpose-built automated coverage or a final manual release pass:

- Editor behavior across the remaining supported WordPress/browser matrix. The authenticated current-version workflow passes; the native date input still needs a focused manual interaction check because browser automation did not dispatch its React change event reliably.
- During final release testing, validate representative public local, hybrid, and remote staging-job URLs with Google's Rich Results Test and URL Inspection.
- Actual email delivery through a configured mail transport; current validation reaches `wp_mail()` but does not assert inbox delivery.
- Apache, Nginx, multisite, Windows/IIS, and hosts where the directory above `ABSPATH` is not writable.
- Broader accessibility validation with actual screen-reader speech output, supported browser/OS combinations, 320% zoom, reduced motion, and high contrast. The focused core-workflow pass is complete.
- Theme compatibility across classic, block, and popular third-party themes.
- High-volume application uploads; query benchmarks and the 1,000-application fixture lifecycle pass locally.
- WordPress Coding Standards and vulnerability scanning. The release-equivalent Plugin Check scan is error-free and the first hosted supported-version matrix passes.
