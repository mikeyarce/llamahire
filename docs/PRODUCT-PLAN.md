# LlamaHire product and execution plan

Status: Working plan
Plan owner: LlamaHire
Last reviewed: July 17, 2026
Current version: 0.1.0 foundation

## 1. Product direction

LlamaHire will be the modern, block-first hiring platform for WordPress. It starts by letting an organization run a complete careers site and application workflow for free, then expands into tools that make hiring teams substantially more productive.

Positioning:

- Product name: **LlamaHire – Job Board & Careers Plugin for WordPress**
- Primary promise: **Modern hiring for WordPress.**
- Experience benchmark: polished, cohesive WordPress-native software with friendly personality.
- Architectural stance: Gutenberg, REST, accessible defaults, explicit privacy, and scalable application storage.
- Competitive stance: solve the hiring workflow as one product instead of recreating an add-on marketplace.

Every roadmap item must improve at least one of these outcomes:

1. Faster hiring.
2. Better candidate experience.
3. Better recruiter experience.
4. Better WordPress experience.
5. Beautiful defaults.
6. Developer experience.
7. Performance.
8. Accessibility.

## 2. Target users and primary jobs to be done

### Primary customers

- Small businesses and SaaS companies.
- Agencies and nonprofits.
- Schools and municipal organizations.

### Primary administrator job

“Help me publish credible openings, receive applications safely, and keep candidates organized without assembling several WordPress plugins.”

### Primary candidate job

“Help me understand the role, decide whether it fits, and apply quickly on any device with confidence that my information is handled responsibly.”

### Secondary future users

- Recruitment firms.
- Membership organizations.
- Public job-board operators.
- Employers purchasing job-listing packages.

## 3. Current verified baseline

Version 0.1.0 is an installable foundation, not yet a public release candidate.

### Implemented

- Unlimited job posts using a public custom post type.
- Draft, published, deadline-closed, and manually closed behavior.
- Departments, location, employment type, workplace mode, salary range, featured flag, featured image/logo, deadline, and duplication.
- Searchable/filterable Jobs Directory block.
- Application Form block and automatic form on single-job pages.
- Careers Page pattern.
- Dedicated applications table.
- Name, email, phone, resume, and cover-letter submission.
- Candidate and employer notification calls.
- New, Reviewing, Rejected, and Hired statuses.
- Private notes, basic search, dashboard counts, recent candidates, and CSV export.
- Protected resume download for administrators; outside-web-root storage when the host allows it.
- JobPosting JSON-LD, job archive URLs, and WordPress metadata.
- Data-preserving uninstall default.
- A repeatable WP-CLI smoke test.
- A versioned experimental public service API for application persistence, notifications, and JobPosting schema, with immutable boot-time registration and contract checks.
- Forward-only, idempotent database migrations and dedicated least-privilege capabilities for jobs, departments, candidate review, management, export, and resume access.
- Private resume-storage and bounded application-query services, paginated administration, streaming CSV batches, normalized job filter metadata, and an initial backend performance review.
- Database-enforced idempotent submissions plus visible failed/partial email state and missing-channel retries.
- A structured Google Jobs model with editor-native readiness guidance, location, remote eligibility, compensation, organization, and publication controls; public job facts and JSON-LD share the same values.

### Important gaps

- Only two of the planned seven dedicated blocks exist.
- Only the Careers Page pattern exists; hero, featured jobs, and department landing patterns are missing.
- Setup onboarding does not exist; organization defaults now have a settings screen but are not yet part of a guided flow.
- Administrators receive the new granular capabilities by default; a setup UI and purpose-built hiring roles are still missing.
- Emails are fixed strings without preview, templates, configured-transport delivery tests, or broader diagnostics beyond per-application failure state.
- Application privacy tools, retention rules, consent text, erasure, and audit history are absent.
- Admin lists need pagination, bulk operations, stronger search/filtering, and accessible responsive behavior.
- Spam protection is limited to a honeypot and nonce.
- The initial service/hook API exists, but REST resources and broader domain events remain incomplete.
- Automated test coverage is a smoke script rather than a full test suite.
- Internationalization, accessibility, performance, compatibility, and WordPress.org checks are incomplete.

The detailed validation evidence is recorded in [VALIDATION.md](VALIDATION.md).
The current Free/Pro extension contract is documented in [PUBLIC-API.md](PUBLIC-API.md).

## 4. Release definition for LlamaHire Free 1.0

Free 1.0 must let a new site owner complete this loop without another plugin:

1. Activate LlamaHire.
2. Complete a short setup flow.
3. Publish a well-structured job.
4. Add a polished careers page using blocks or a pattern.
5. Receive a candidate application and resume securely.
6. Notify the candidate and hiring inbox.
7. Review, annotate, search, and update the application.
8. Export application data and honor privacy requests.
9. Expose valid Google for Jobs data.

The median first-time user should be able to publish a working careers page in under five minutes.

## 5. Execution roadmap

### Milestone 0 — Stabilize the foundation

Goal: make the current vertical slice safe to build upon.

Work:

- Completed: database migrations independent of activation hooks, with an atomic lock, forward-only versions, replay tests, and multisite activation support.
- Completed: dedicated capabilities for jobs, departments, application viewing/management, exports, and resumes; administrators receive them by default.
- Completed foundation: public repository, notification, and schema service contracts. Remaining direct application-list queries will move behind query APIs with pagination.
- Add structured error logging that excludes candidate content; sanitized per-application notification error codes are complete, while general operational logging remains.
- Completed: make duplicate submission handling idempotent with a browser submission key and database uniqueness.
- Validate MIME type, extension, file signature, and configured upload limits consistently.
- Define the private-storage contract and host fallback warnings.
- Add application and job factories for tests.
- Completed foundation: retain the fast 61-check smoke command and add isolated `wp-env`/Playwright integration coverage for the complete browser hiring workflow. Continue converting domain checks to focused unit/integration tests as the product grows.
- Completed: authenticated application review, status, notes, formula-safe CSV, public resume upload, and authorized protected resume download are formalized in GitHub Actions. Confirm the first hosted supported-version matrix run when the repository is connected.
- Add email interception tests for subject, recipients, and escaped candidate content.

Acceptance criteria:

- Fresh install, upgrade, deactivate/reactivate, and multisite activation do not lose or corrupt data.
- A failed notification cannot produce a 500 response or duplicate a stored application.
- Only explicitly authorized hiring users can access candidate data.
- Every public submission outcome is understandable and recoverable.
- The full core workflow passes in continuous integration on supported PHP and WordPress versions.

### Milestone 1 — Five-minute setup and job authoring

Goal: make the first-run experience feel like a modern product.

Work:

- Add a welcome/setup flow with progress and skip behavior.
- Create or select the Careers page.
- Configure organization name, logo, default currency, notification inbox, and privacy text.
- Completed foundation: add a polished job-settings panel using editor-native controls, grouped readiness guidance, and server-side suppression of incomplete schema. Continue visual/accessibility refinement during this milestone.
- Clarify Published versus Closed state in list and editor views.
- Add validation for salary bounds and deadlines.
- Add job preview and duplicate flows with clear feedback.
- Provide sensible organization and location defaults.
- Add contextual empty states and links to the next action.

Acceptance criteria:

- A new user can activate, configure, publish a job, and view a careers page in under five minutes.
- Job authors never need to edit raw custom fields or know a post ID.
- Closing and reopening a job are deliberate, reversible actions.
- Required organization data for complete schema is collected once and reused. Completed foundation; setup flow remains.

### Milestone 2 — Complete the block-first careers experience

Goal: make blocks the product surface, with beautiful defaults across themes.

Blocks:

- Jobs Directory.
- Job Search.
- Job Filters.
- Featured Jobs.
- Job Card.
- Single Job Details.
- Application Form.

Patterns:

- Careers Page.
- Careers Hero.
- Featured Jobs.
- Department Landing Page.

Work:

- Split search and filters into composable blocks while keeping an easy all-in-one directory variation.
- Define block context so Job Card and job-detail children compose without manual IDs.
- Add query pagination, result counts, clear filters, empty states, and URL-preserved filter state.
- Support department, employment type, workplace, location, featured, and keyword filtering.
- Use semantic markup and predictable design tokens that inherit theme styles.
- Add block variations and previews.
- Use the Interactivity API only where it improves navigation and remains progressively enhanced.
- Document block attributes and extension points.

Acceptance criteria:

- Every planned block works independently and in the supplied patterns.
- Search and filtering work without JavaScript and become smoother when JavaScript is available.
- Keyboard focus, announcements, labels, target sizes, and contrast satisfy WCAG 2.2 AA.
- Layouts remain usable from 320% zoom/mobile through wide desktop across classic and block themes.
- No shortcode is required for a new implementation.

### Milestone 3 — Trustworthy candidate applications

Goal: deliver a fast, respectful, privacy-conscious application experience.

Work:

- Add configurable required/optional states for phone, resume, and cover letter.
- Add clear consent/privacy language and a privacy-policy link.
- Preserve safe field values after recoverable validation errors.
- Add server-side rate limits and configurable anti-spam integration points.
- Add accessible upload progress/feedback where supported.
- Add email sender settings, previews, plain-text templates, and delivery diagnostics.
- Add duplicate-application policy and clear candidate messaging.
- Add retention periods, scheduled deletion, manual erasure, and resume replacement/deletion support.
- Integrate with WordPress personal-data exporters and erasers.
- Record a minimal audit trail for status and deletion events.

Acceptance criteria:

- Candidate submissions work with keyboard and screen reader on mobile and desktop.
- Candidate data never appears in public media URLs, logs, schema, caches, or analytics payloads.
- Site owners can explain where candidate data is stored, how long it is retained, and remove it on request.
- Notification failure is visible to administrators without blocking or duplicating the application.
- Spam controls do not create an inaccessible challenge by default.

### Milestone 4 — Recruiter operations

Goal: make the free inbox sufficient for a small hiring team.

Work:

- Add paginated application lists with job, status, date, and keyword filters.
- Add bulk status changes and exports with explicit confirmation.
- Improve candidate detail hierarchy, responsive behavior, and keyboard flow.
- Add job-level application counts and direct navigation.
- Add saved admin preferences and useful empty states.
- Define CSV columns, encoding, date/time semantics, and formula-injection protection as a stable contract.
- Add dashboard date ranges and accurate open-job counts.
- Add configurable hiring roles and capability assignment.

Acceptance criteria:

- A hiring user can find any candidate in a representative 10,000-application dataset quickly.
- Bulk actions are reversible where practical and cannot cross permission boundaries.
- CSV opens safely in common spreadsheet tools and retains Unicode content.
- Recruiters can operate the workflow without administrator access.

### Milestone 5 — SEO, developer platform, and integrations foundation

Goal: make Free 1.0 discoverable, extensible, and stable.

Work:

- Implement the Google Jobs schema contract in section 7, including organization identity, employer-provided salary ranges and pay units, complete locations, remote eligibility, stable identifiers, and expiry behavior.
- Validate structured data against Google’s current JobPosting requirements and content policies using representative local, hybrid, and fully remote fixtures.
- Remove `JobPosting` markup promptly when a job closes, or publish an elapsed `validThrough` value while the historical page remains available.
- Define versioned REST endpoints and permission callbacks for jobs and applications.
- Publish PHP actions/filters for job fields, application validation, persistence, notifications, and exports.
- Add webhook-ready domain events without shipping paid automation in Free.
- Add conflict-safe rewrite handling and clear permalink diagnostics.
- Ensure canonical job URLs appear in XML sitemaps with accurate modification times, while directories and filtered result pages never emit `JobPosting` markup.
- Provide an optional Google Indexing API integration boundary for publishing, updating, and removing job URLs; credentials and submission outcomes must be observable and secure.
- Document the data model, privacy model, extension API, and compatibility policy.

Acceptance criteria:

- Representative local, hybrid, and remote jobs pass structured-data validation without critical errors.
- REST endpoints expose no candidate data without explicit application capabilities.
- Public extension points have tests and backward-compatibility expectations.
- Closing a job updates public availability, schema, and application behavior consistently.

### Milestone 6 — Free 1.0 release readiness

Goal: ship a trustworthy WordPress.org release.

Work:

- Run WordPress Coding Standards, Plugin Check, static analysis, JavaScript linting, and vulnerability scanning.
- Test the supported WordPress/PHP matrix, multisite, common mail transports, and representative hosts.
- Test classic themes, block themes, RTL, localization, and no-JavaScript behavior.
- Complete accessibility and performance audits with documented budgets.
- Add upgrade, rollback, backup, and uninstall test scenarios.
- Complete readme, screenshots, onboarding copy, privacy documentation, changelog, support policy, and release checklist.
- Conduct a closed beta, triage findings, and freeze release scope.

Release gates:

- No known critical/high security or privacy defects.
- No known data-loss or duplicate-application defects.
- WCAG 2.2 AA audit has no critical blocker.
- Public pages meet agreed performance budgets on representative hosting.
- All supported-environment tests and core end-to-end flows pass.
- WordPress.org assets and policy review are complete.

## 6. Free and Pro architecture

### Decision

Build **LlamaHire Free as the required platform plugin** and **LlamaHire Pro as a separate add-on plugin** in **two repositories**:

- Public `llamahire` repository for Free source, issues, discussions, community pull requests, roadmap visibility, and WordPress.org releases.
- Private `llamahire-pro` repository for commercial source, Pro releases, and the Free+Pro compatibility matrix.

Continuous integration produces and tests one independent installable ZIP from each repository. It does not create two variants from conditionals in a shared runtime tree.

Free must remain a complete careers and application product. Pro depends on Free’s documented contracts and adds team-productivity features. Pro must not copy or replace Free classes, database migrations, blocks, or application services.

Target repositories:

```text
llamahire/                    # Public repository
├── includes/                 # Free runtime and public contracts
├── tests/                    # Free unit, integration, and browser tests
└── .github/                  # Public issues, CI, and Free release workflow

llamahire-pro/                # Private repository
├── includes/                 # Additive Pro runtime
├── tests/                    # Pro and Free+Pro compatibility tests
└── .github/                  # Private CI and commercial release workflow
```

The current repository already has the correct top-level shape for the public Free repository. No monorepo directory move is needed.

### Options considered

| Model | Benefits | Costs and risks | Decision |
|---|---|---|---|
| Free and Pro in separate repositories | Public issues/contributions, clear source ownership, strong access separation, and a naturally public Free history | Cross-repository changes and compatibility testing need deliberate automation | **Recommended** |
| Free platform + Pro add-on in one private monorepo | Atomic changes, shared tooling, and simple combined tests | Public Free issues and pull requests point at a mirror rather than the development source; accidental Pro publication risk | Reject for a community-facing Free plugin |
| Two standalone plugins generated from one conditional codebase | Either ZIP can appear self-contained | Duplicated runtime code, class conflicts, unclear migrations, difficult coexistence, and fragile build flags | Reject |
| One plugin unlocked by a license flag | Simplest runtime dependency model | Ships Pro code to every Free installation and does not provide genuinely separate plugins | Reject for the planned distribution model |
| Shared runtime “core” package copied into both ZIPs | Apparent code reuse | Duplicate classes/services when both plugins are active and ambiguous ownership of upgrades | Reject; share tooling, not runtime ownership |

Do not synchronize source between repositories with subtrees or copied “core” directories. The dependency boundary is Free’s versioned public API. Small lint/build configurations may initially be duplicated; extract a public development-tooling package only after repetition creates a real maintenance cost.

### Runtime contracts

- Free owns jobs, applications, resumes, privacy, base emails, schema, public blocks, capabilities, migrations, and core REST resources.
- Pro owns pipeline stages, assignments, ratings, tags, automation, custom forms, portal, analytics, and integrations.
- Free exposes documented PHP interfaces, actions/filters, REST routes, and domain events for Pro.
- Pro uses the `LlamaHire\Pro` namespace and its own prefixed options, tables, scripts, styles, REST namespace, and migration version.
- Pro checks the installed Free version before booting. If Free is missing or incompatible, Pro remains inert and shows an administrator notice without producing a fatal error.
- Free never checks whether Pro is active to perform a core user outcome. It may expose neutral extension points and UI slots.
- Pro extensions must be additive. Deactivating Pro leaves jobs and applications operable in Free and preserves Pro data for later reactivation.
- Shared interfaces needed at runtime belong to Free. Do not ship a second copy in Pro.
- Free public API removals require deprecation across at least one documented compatibility window.

### CI and release packaging

The public Free workflow should:

1. Install locked PHP and JavaScript development dependencies.
2. Run Free coding standards, static analysis, unit, integration, security, accessibility, and browser tests without private credentials.
3. Test the documented public API contract and deprecation policy.
4. Create `llamahire-{version}.zip` without development files or unintended source maps.
5. Install the artifact into a clean WordPress instance and rerun activation/upgrade smoke tests against the packaged ZIP.
6. Generate checksums and a software bill of materials.
7. Publish the Free artifact to a GitHub release and, after approval, deploy it to WordPress.org SVN.

The private Pro workflow should:

1. Check out Pro plus Free at the compatibility matrix versions.
2. Test Pro against Free `main`, the latest Free release, and the oldest supported Free release.
3. Test Pro with Free missing, inactive, newer than tested, and older than supported.
4. Run the complete Free+Pro browser journeys and upgrade combinations.
5. Build and clean-install `llamahire-pro-{version}.zip` beside packaged Free.
6. Generate checksums/software bill of materials and publish only to the commercial release channel.

Free pull requests from forks must never receive Pro repository credentials. Compatibility against unreleased Free changes runs from scheduled/private Pro CI, a manually approved workflow, or a narrowly scoped repository dispatch after trusted Free changes land.

Release versions do not have to match, but every Pro release declares a minimum and tested-through Free version. Compatibility is machine-readable and covered by the release matrix.

### Public collaboration and release coordination

- Use the public Free repository as the canonical source for Free bugs, feature requests, discussions, documentation, and contribution guidelines—not a generated mirror.
- Keep Pro support/customer issues private, while moving confirmed Free defects into public issues after removing customer-sensitive context.
- Label issues by `free`, `pro`, `public-api`, `security`, and milestone; public issues must not promise Pro delivery dates.
- Publish the supported Free/Pro version matrix in both repositories and in Pro release notes.
- A Free public-API change requires a corresponding private Pro compatibility run before the Free release is approved.
- Use narrowly scoped release tokens and protected production release environments.
- Prefer reproducible release scripts invoked locally and by CI so GitHub Actions is automation, not undocumented product logic.

## 7. Google Jobs SEO contract

Google eligibility is a product feature, not a best-effort JSON blob. The visible job page, application state, canonical URL, and structured data must describe the same real opening.

Official reference: [Google Search Central JobPosting documentation](https://developers.google.com/search/docs/appearance/structured-data/job-posting).

### Page and content rules

- Emit one `JobPosting` entity only on the canonical page for one specific job. Never emit it on directories, search results, department archives, or filtered pages.
- Keep the page crawlable without login, `noindex`, robots blocking, or an application-wall redirect.
- Show a complete description visibly on the page, including responsibilities, qualifications, skills, working hours, education, and experience where applicable.
- Keep every structured value consistent with visible content. Salary, workplace mode, location, expiry, organization, and title in JSON-LD must be visible to candidates.
- Use the real job title only—no company name, location, salary, dates, “apply now,” or keyword stuffing in `title`.
- Provide a usable application path and never require applicants to pay.
- Set `directApply` only while the LlamaHire form provides a short application path without unnecessary intermediate steps.

### Required schema model

- `@context`: `https://schema.org`
- `@type`: `JobPosting`
- `title`: concise visible job title.
- `description`: complete visible job description in supported HTML.
- `datePosted`: original publication timestamp in ISO 8601, not the last edit date.
- `hiringOrganization`: organization name, canonical website URL, and a crawlable logo URL when available.
- `identifier`: stable organization-scoped job ID that does not change when the title or URL changes.
- `employmentType`: one or more supported case-sensitive values such as `FULL_TIME`, `PART_TIME`, `CONTRACTOR`, `TEMPORARY`, `INTERN`, `VOLUNTEER`, `PER_DIEM`, or `OTHER`.
- `validThrough`: ISO 8601 date/time whenever the job has an application deadline.

### Salary

- Store salary as employer-provided base compensation, never an inferred or market-estimated value.
- Store and display currency plus the pay period separately from the amount.
- Use only Google’s case-sensitive `unitText` values: `HOUR`, `DAY`, `WEEK`, `MONTH`, or `YEAR`.
- For a range, emit `minValue` and `maxValue` inside `baseSalary.value`; for an exact salary, emit `value`.
- Never emit zero for a missing range boundary. Omit incomplete or unknown values instead.
- Validate `minValue <= maxValue`, a three-letter currency code, and a supported pay unit before publication.
- Display the same salary/range, currency, and pay period prominently on the job page.

### Location and remote work

- Physical and hybrid jobs need structured `jobLocation` entries using `Place` and a complete `PostalAddress`: locality, region where relevant, postal code where available, and two-letter country code.
- Support multiple physical locations as an array rather than combining them into a free-text string.
- Use `jobLocationType: TELECOMMUTE` only for jobs that are fully remote—not hybrid, occasionally remote, or negotiable.
- A fully remote job must define at least one eligible applicant country or administrative area through `applicantLocationRequirements`, and the visible description must state those restrictions.
- Support “remote within Canada,” multiple eligible countries, and a physical-location-plus-remote option without defaulting to an inaccurate “Worldwide” value.

### Closing, canonicalization, and discovery

- When a job expires or closes, immediately stop accepting applications and remove `JobPosting` markup, set `validThrough` in the past, or return `404/410`; never leave active markup on an unavailable job.
- Retaining a human-readable historical page is allowed only when it clearly says the role is closed and no active `JobPosting` markup remains.
- Canonicalize duplicates to one job URL.
- Include canonical job URLs in XML sitemaps with accurate `lastmod`; exclude search, filters, and dynamic result URLs.
- Define an optional Indexing API adapter to notify Google when job URLs are published, materially updated, or removed. Free 1.0 should provide the event/API boundary even if credentialed submission ships later.

### Validation and monitoring

- Unit-test the schema builder with local, hybrid, fully remote, salary range, exact salary, no salary, expired, and closed fixtures.
- Assert visible-page/schema parity for salary, location, title, organization, employment type, and deadline.
- Validate representative URLs with Google’s Rich Results Test before release and URL Inspection during beta.
- Track schema generation errors and Indexing API outcomes without sending candidate data.
- Recheck the official Google documentation at each release because supported properties and policies can change.

Version 0.1.0 now implements the core structured model, removes the inaccurate “Worldwide” default, supports Google pay units and structured physical/remote locations, and suppresses markup for jobs missing the minimum organization/location data. Remaining release work includes multiple physical locations, exact/no-salary fixtures, expired/closed fixtures, Rich Results validation, sitemap lifecycle checks, and accessibility/theme testing of the editor and public facts panel.

## 8. Post-1.0 product phases

### Phase 2 — LlamaHire Pro

Pro should improve team productivity without making Free incomplete.

- Visual applicant pipeline with custom stages and drag/drop keyboard alternatives.
- Ratings, tags, assignments, private collaboration notes, and application history.
- Custom form fields, screening questions, job-specific forms, and conditional logic.
- Email templates and event automation for review, interviews, rejection, and reminders.
- Candidate portal using expiring magic links, resume updates, history, and withdrawal.
- Analytics for views, applications, conversion, time to hire, funnel, and source attribution.
- Webhooks and integrations for Slack, Teams, Zapier, Calendly, and calendars.

Before Pro development begins, Free must expose stable domain events, capabilities, repositories, and REST boundaries. Pro must depend on public contracts rather than replacing Free internals.

### Phase 3 — Job-board commerce

- Stripe-powered packages without a WooCommerce dependency.
- Paid and free submission packages.
- Featured listings and expiration.
- Employer accounts and dashboard.
- Moderation, refunds, invoices, taxes, and abuse controls.

The Free job record already reserves an internal organization owner and stores organization-facing values independently of the site-wide defaults. Pro commerce can attach that owner to an employer account and entitlement without replacing Free’s job, editor, URL, or schema model.

Commerce begins only after the careers/ATS data model proves it can support multiple employers without leaking candidate data across tenants.

### Phase 4 — ATS expansion

- Interview scheduling.
- Team collaboration and evaluation.
- Talent pools.
- Internal hiring.
- Referral programs.
- Carefully scoped AI assistance with human review, provenance, privacy controls, and bias evaluation.

## 9. Technical architecture

### WordPress boundaries

- Jobs remain a custom post type to preserve editor, revision, taxonomy, template, and REST compatibility.
- Applications remain in custom tables for privacy, query performance, retention, and reporting.
- Blocks and patterns are the supported presentation API.
- Shortcodes may be added only for documented legacy migration needs.
- WordPress capabilities—not menu visibility—authorize every candidate-data operation.

### Application domain

Before Free 1.0, separate these responsibilities:

- Job model and availability policy.
- Application validation and submission service.
- Application repository and query objects.
- Resume storage provider and authorized download service.
- Notification service with observable outcomes.
- Privacy/retention service.
- Schema builder.
- Admin and block presentation layers.

Views and blocks should call these APIs instead of constructing direct SQL or storage paths.

### Database evolution

- Maintain an explicit schema version separate from the plugin version.
- Run idempotent migrations during normal admin requests, not only activation.
- Add indexes only from measured query patterns.
- Store timestamps in UTC and convert for display.
- Define deletion behavior when a job, user, site, or plugin is removed.
- Never remove production data on ordinary deactivation or upgrade.

### Resume storage

- Prefer a directory outside the public web root.
- Provide host-specific protected fallback behavior and an admin health warning.
- Generate opaque storage names and preserve sanitized display names separately.
- Stream downloads after nonce and capability checks with safe headers.
- Prevent executable file types and verify actual content.
- Define backup, multisite, offloaded-media, and object-storage contracts before adding integrations.

### Compatibility

- Support WordPress and PHP versions stated in the public readme and test every supported combination.
- Follow WordPress deprecation policy and avoid private Gutenberg APIs.
- Progressive enhancement is required for candidate-critical flows.
- Avoid a framework dependency unless it materially improves maintainability and has an explicit upgrade strategy.

## 10. Security and privacy requirements

- Treat resumes, cover letters, notes, email addresses, phone numbers, and application history as sensitive data.
- Apply nonce/CSRF protection and capability checks independently.
- Sanitize on input, validate against domain rules, and escape for the output context.
- Use prepared database queries and bounded/paginated reads.
- Rate-limit public writes and make submission processing idempotent.
- Do not include candidate content in logs, URLs, telemetry, or exception messages.
- Protect CSV consumers from spreadsheet formula injection.
- Provide retention, export, erasure, and documented backup limitations.
- Security-sensitive failures should default closed and remain diagnosable by authorized administrators.

Threat modeling is required for each milestone involving uploads, public forms, candidate access, webhooks, magic links, commerce, or AI.

## 11. Accessibility and design requirements

- WCAG 2.2 AA is the minimum release target.
- All functionality must work by keyboard, including future pipeline interactions.
- Use semantic headings, landmarks, labels, descriptions, errors, and live announcements.
- Never use color alone for meaning.
- Respect zoom, text resizing, reduced motion, contrast preferences, and RTL.
- Inherit theme typography/colors where practical while maintaining safe accessible defaults.
- Test candidate flows on small touch screens and recruiter flows at high information density.
- Mascot and personality may make the experience warm but must not obscure important actions or sensitive moments.

## 12. Performance budgets

Budgets will be finalized with representative hosting during Milestone 0. Initial targets:

- No front-end JavaScript for the basic job page or application submission.
- Load block assets only when a LlamaHire block or job view needs them.
- Avoid unbounded application queries.
- Directory queries remain responsive with 10,000 jobs under an indexed representative dataset.
- Admin search/filter remains responsive with 100,000 applications under an indexed representative dataset.
- Schema generation adds no external request to page rendering.

Every new analytics or integration feature must define its query and network cost before implementation.

## 13. Quality strategy

### Automated layers

- Fast smoke test for installation and the primary vertical slice.
- PHP unit tests for domain rules, validation, capabilities, schema, privacy, and CSV safety.
- WordPress integration tests for migrations, database queries, post/taxonomy behavior, REST, email dispatch, uploads, and multisite.
- JavaScript unit tests for editor controls and interactive state.
- Browser end-to-end tests for setup, job publication, careers filtering, candidate submission, and recruiter review.
- Accessibility automation supplemented by manual assistive-technology testing.
- Static analysis, coding standards, dependency scanning, and Plugin Check.

### Required test fixtures

- Local, hybrid, and remote jobs.
- Open, expired, manually closed, draft, and deleted jobs.
- Salary/no-salary and multiple currencies.
- Valid/invalid/oversized resumes.
- Unicode, RTL, long content, malicious markup, and spreadsheet-formula candidate values.
- Multiple roles and denied-permission users.
- Large job and application datasets.

### Definition of done

A feature is done only when:

- Its user outcome and acceptance criteria pass.
- Permissions, privacy, failure, empty, loading, and high-volume states are addressed.
- Automated coverage is appropriate to its risk.
- Accessibility and responsive behavior are verified.
- Public APIs and user-facing behavior are documented.
- No unrelated regression appears in the core end-to-end flow.

## 14. Measurement

Free 1.0 product metrics:

- Setup completion rate.
- Time from activation to first published job.
- Time from activation to functioning careers page.
- Successful application completion rate.
- Application error and duplicate rate.
- Notification failure rate.
- Active sites publishing at least one job.

Telemetry must be opt-in, disclose collected fields, avoid candidate data, and degrade cleanly when disabled. Until that system exists, beta measurement should use consented research and support feedback.

## 15. Decisions required

Resolve these during Milestones 0–1:

1. Minimum WordPress/PHP versions and support window for Free 1.0.
2. Dedicated hiring roles and default capability assignment.
3. Whether a “company logo” is organization-wide, job-specific, or both.
4. Default required/optional application fields.
5. Default retention period and administrator warnings.
6. Supported resume types and maximum size.
7. Remote-job location requirements for schema and candidate clarity.
8. Email sender identity, reply-to behavior, and failure visibility.
9. Multisite storage/isolation behavior.
10. Anonymous aggregate telemetry policy.
11. Public beta cohort and success thresholds.

No unresolved decision should silently become architecture through convenience.

## 16. Immediate next iteration

Start Milestone 0 with this order:

1. Completed: add a schema migration runner and dedicated schema version.
2. Completed: introduce hiring capabilities and test candidate-data boundaries.
3. Completed foundation: define the Free public API surface and Pro boot/compatibility contract.
4. Completed: extract application queries and resume storage from presentation code; repository, query, storage, notification, and schema services are active.
5. Completed: define private-storage fallbacks, hide storage paths, validate the HTTP upload boundary, and expose a Site Health check.
6. Completed initial gate: benchmark application and job queries, remove N+1 behavior, add composite indexes, normalize directory filter metadata, and document remaining MySQL/MariaDB release tests.
7. Completed: add database-enforced duplicate-submission idempotency, channel-level notification state, administrator visibility, and missing-channel retries.
8. Completed foundation: replace the location/salary fields with structured addresses, remote eligibility, currency/pay unit, stable identifiers, organization defaults/overrides, editor-native readiness guidance, and visible page/schema parity.
9. Completed harness: authenticated editor behavior, expanded schema fixtures, public submission, admin review, formula-safe CSV, and authorized resume download pass in an isolated WordPress environment and are defined in GitHub Actions. Next confirm the first hosted matrix run; email failure/retry interception already passes.
10. Add public-Free and private-Pro CI/release scripts only after service and plugin boundaries are tested in the current Free tree.
11. Run the first accessibility/security/SEO review on the stabilized vertical slice.
12. Re-review Milestone 1 scope using evidence from those tests.

The plan should be reviewed at each milestone boundary. Update status, decisions, risks, and acceptance evidence in this document rather than maintaining a separate unconnected backlog.
