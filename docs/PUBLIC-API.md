# LlamaHire Free public API

API version: `1.0.0-alpha.12`
Plugin version introduced: `0.1.0`
Status: experimental until API 1.0

## Purpose

LlamaHire Free is the required platform for LlamaHire Pro and third-party extensions. Free owns the job, application, resume, privacy, schema, and core workflow data. Extensions add behavior through the contracts and hooks documented here; they must not copy Free runtime classes or query private storage directly.

The public API has its own version because plugin releases and compatibility contracts evolve at different rates:

```php
LLAMAHIRE_API_VERSION
```

Pro should also use the WordPress plugin header `Requires Plugins: llamahire`, then verify the API version before booting.

## Boot lifecycle

### `llamahire_register_services`

Runs during `init` after Free registers its defaults and before the service container is locked.

Arguments:

1. `LlamaHire\Contracts\Service_Container $services`
2. `string $api_version`

Extensions may add their own uniquely named service objects or replace a Free service with an object implementing the required Free contract. A non-conforming replacement stops initialization immediately instead of failing later in a candidate workflow.

After this hook finishes, the default candidate-data lifecycle binds to the final repository and resume-storage services, including when an extension retains or decorates that lifecycle instance. An explicitly replaced lifecycle service remains under the extension's control.

```php
add_action(
	'llamahire_register_services',
	static function ( $services, $api_version ) {
		if ( version_compare( $api_version, '1.0.0-alpha.8', '<' ) ) {
			return;
		}

		$services->set( 'my-company.example_service', new Example_Service() );
	},
	10,
	2
);
```

### `llamahire_ready`

Runs after Free post types, blocks, application handlers, admin screens, SEO hooks, and immutable services are registered.

Argument:

1. `LlamaHire\Plugin $plugin`

Pro should begin normal runtime integration here:

```php
add_action(
	'llamahire_ready',
	static function ( $free ) {
		if ( version_compare( $free->api_version(), '1.0.0-alpha.8', '<' ) ) {
			return;
		}

		$applications = $free->services()->get(
			\LlamaHire\Service_IDs::APPLICATION_REPOSITORY
		);
	}
);
```

Calling `Plugin::services()` before initialization throws a `LogicException`. Changing the container after initialization also throws; runtime replacement would make behavior depend on hook order and is intentionally unsupported.

## Stable service identifiers

Use constants rather than copying identifier strings:

| Constant | Contract | Owner |
|---|---|---|
| `Service_IDs::APPLICATION_REPOSITORY` | `Contracts\Application_Repository` | Free |
| `Service_IDs::APPLICATION_QUERY` | `Contracts\Application_Query` | Free |
| `Service_IDs::CANDIDATE_DATA` | `Contracts\Candidate_Data_Lifecycle` | Free |
| `Service_IDs::NOTIFICATIONS` | `Contracts\Notification_Service` | Free |
| `Service_IDs::RESUME_STORAGE` | `Contracts\Resume_Storage` | Free |
| `Service_IDs::SCHEMA_BUILDER` | `Contracts\Schema_Builder` | Free |

### Application repository

The repository owns application-record persistence:

- `create( array $application ): int|WP_Error`
- `create_once( array $application ): array|WP_Error`
- `find_duplicate( $job_id, $email ): int`
- `find( $application_id ): ?object`
- `update( $application_id, array $changes ): bool|WP_Error`
- `delete( $application_id ): bool`
- `record_notification_result( $application_id, array $result ): bool|WP_Error`

`create_once()` requires a UUID submission key and returns an `id`, a `created` boolean, and, when applicable, a `reason`. Reusing the same key converges on the original application. By default, a different submission key with the same normalized candidate email and job also converges on the canonical application with reason `job_email`. Both identities are protected against concurrent requests by database uniqueness. `find_duplicate()` supports an early check before storing an uploaded resume. Extensions that receive public submissions should use `create_once()` and send side effects only when `created` is true.

The `llamahire_duplicate_application_policy` filter receives the default `preserve` policy, job ID, sanitized email, and canonical existing application ID when known. Returning `allow` permits an additional job/email record while submission-key retries remain idempotent. The default public flow preserves the original fields and resume, sends no repeat notifications, consumes the normal submission rate limit, and returns exactly the same generic success state as a new application. `llamahire_duplicate_application_ignored` fires with the canonical application and job IDs when that policy handles a public request.

`record_notification_result()` persists channel-level success, attempt count, aggregate status, and sanitized error codes. It never stores mail error messages or candidate content. `status` is the public mutable update field. For backward compatibility, a non-empty `notes` value passed to the repository appends a private note; it never replaces or clears prior notes. Extensions must use this contract rather than relying on table names or direct SQL. Resume lifecycle operations belong to the separate resume-storage contract.

### Application query

The bounded query service provides paginated `search()`, grouped `counts()`, bounded `recent()`, and batched `export_rows()` operations. `search()` also accepts `stage_changed_before` to find applications that have remained in their current stage since a UTC date-time; legacy rows fall back to their receipt time. Each accepts an optional `author_id` job-owner filter so multi-employer integrations can preserve tenant boundaries. It never exposes the private resume token/path. Extensions must not query the applications table directly.

### Recruiter REST endpoints

`GET /llamahire/v1/applications` requires `llamahire_view_applications` and returns the bounded, ownership-scoped recruiter list without notes, cover letters, phone numbers, or resume identifiers. `GET /llamahire/v1/applications/{id}` requires ownership-scoped application-view permission and returns one review record with status, up to 20 append-only private notes, available application materials, and up to 20 privacy-safe activity entries for the bounded history modals. Resume responses expose only the original filename, type, a capability-checked nonce download URL, and—when the saved file is a browser-renderable PDF—a separate nonce preview URL. Preview delivery remains ownership checked, private, non-cacheable, and inline only for validated PDFs; DOCX files continue to download. Storage tokens and paths remain private. `POST /llamahire/v1/applications/{id}` requires ownership-scoped application-management permission and updates only the allow-listed `status` field. `POST /llamahire/v1/applications/{id}/notes` requires the same ownership-scoped management permission and appends one private note of up to 5,000 characters. It never edits or deletes earlier notes. `POST /llamahire/v1/applications/bulk-status` requires `llamahire_manage_applications`, accepts up to 100 numeric `application_ids` plus one allow-listed workflow `status`, and verifies access to every selected record before updating any of them. Status changes preserve private notes and use the repository contract so the normal privacy-safe audit events are recorded.

WordPress's public job REST responses include candidate-facing structured job metadata. For jobs using LlamaHire's internal application form, the private notification recipient in `application_target` is redacted unless the requester can edit that specific job. Authorized editor responses retain the value, and public external application URLs or email addresses remain available because those are intentionally candidate-facing routes.

### Applications CSV export

The recruiter inbox export (`admin-post.php?action=llamahire_export`, requiring `llamahire_export_applications` and a valid nonce) is a stable contract for spreadsheets and downstream integrations:

- Columns are fixed and ordered: `ID`, `Job`, `Name`, `Email`, `Phone`, `Cover letter`, `Status`, `Received`. New columns require a documented contract change, not a silent addition.
- `ID` is the numeric application record number. `Job` is the current job post title, or a `Deleted job #N` placeholder after the job is removed. `Status` is the raw workflow key such as `new`, `reviewing`, `interviewing`, `offer`, `hired`, or `rejected`.
- `Received` is stored submission time in UTC as `Y-m-d H:i:s`. Administration screens display localized equivalents; the file always contains UTC so exports remain comparable across server time zones.
- The file is UTF-8 with a leading byte-order mark for spreadsheet compatibility, comma-separated, `"`-quoted, and uses `\n` row endings.
- Cell values that would evaluate as a formula in common spreadsheet applications—those whose first non-whitespace character, ignoring a leading UTF-8 BOM or other control characters, is `=`, `+`, `-`, or `@`—are prefixed with a single `'`.
- The export honors every active inbox filter (search, candidate, email, job, status, email status, and received date) plus the requester's ownership scope, streams rows in bounded batches of 500 in descending record order, and never includes resume files or storage tokens, private notes, notification diagnostics, or audit events.
- Attachments are named `llamahire-applications-YYYY-MM-DD.csv` using the current UTC date.

### Resume storage

The resume service owns validation, opaque storage tokens, deletion, availability checks, authorized streaming, and storage health. Extensions may call `has_resume()` after checking application-view permission, but must use `stream()` only after checking `Capabilities::DOWNLOAD_RESUMES` and a request nonce.

Storage tokens and filesystem paths are internal even when passed between Free services. Pro must store application IDs, not resume paths.

Resume uploads are restricted to 5 MB PDF and DOCX files and must pass filename/MIME and content-signature validation. DOCX fails closed when `ZipArchive` is unavailable and otherwise must contain the expected Word document entries without path traversal or VBA macros. Trusted security extensions may perform additional inspection with `llamahire_validate_resume_upload`; return a `WP_Error` or `false` to reject the upload. Legacy DOC is disabled for new uploads by default because its OLE container cannot be inspected deeply here. A trusted scanner integration may opt in with `llamahire_allow_legacy_doc_uploads`, but it must also reject unsafe content through `llamahire_validate_resume_upload`. Existing DOC resumes from an earlier release remain recognized as `application/msword` when delivered through the protected download endpoint.

The default `local_private` driver stores new production resumes outside the WordPress web root and fails closed when that directory is unavailable. It uses the WordPress Filesystem API for directory, read, write, and delete operations. Local and development environments retain a deny-file-protected `uploads/llamahire-private` fallback for disposable development systems; production environments cannot opt into that fallback.

WordPress VIP sites may explicitly select the `vip_acl` driver before LlamaHire initializes:

```php
add_filter(
	'llamahire_resume_storage_driver',
	static function () {
		return 'vip_acl';
	}
);
```

Put that filter in a client MU plugin so it is registered before the normal `init` hook. The driver requires WordPress VIP Access-Controlled Files to be activated for the environment and either the restrict-all or restrict-unpublished mode to be enabled. If those platform signals are unavailable, storage health is critical and new uploads fail closed.

The VIP driver writes through the WordPress Filesystem API to the dedicated `uploads/llamahire-private` prefix, creates a WordPress attachment record for lifecycle compatibility with VIP's object store, and stores only an opaque `attachment:<id>` token with the application. Its `vip_files_acl_file_visibility` rule always marks that prefix private and denied, including when the attachment has no parent post. Authorized recruiters still download through LlamaHire's nonce-, capability-, and ownership-protected endpoint; they never receive the underlying Media Library URL.

General custom storage implementations should continue to replace `Service_IDs::RESUME_STORAGE` during `llamahire_register_services` and implement `Contracts\Resume_Storage`. A driver that stores its own opaque tokens must also register `llamahire_uninstall_delete_resume_token` from an active companion or MU-plugin bootstrap, because normal LlamaHire runtime hooks do not run during uninstall. The filter receives `null`, the sensitive opaque token, and the current site ID. Return `true` only after deletion succeeds, `false` on a deletion failure, or the incoming value when the token belongs to another integration. Tokens must never be logged.

### Candidate-data lifecycle

The lifecycle service coordinates application records and their private resume files:

- `cleanup_expired( $limit = 250, $now = null ): array` erases a bounded batch older than the configured retention period.
- `erase( $application_id ): bool|WP_Error` permanently removes one application and its managed resume.
- `delete_resume( $application_id ): bool|WP_Error` permanently removes only the resume and clears its record fields.
- `replace_resume( $application_id, array $file ): bool|WP_Error` validates and stores a replacement before removing the previous managed file.

The `retention_days` setting accepts disabled retention or a documented preset from 30 days through five years. A daily WordPress cron event runs the bounded cleanup; disabling retention keeps records until an authorized erasure. Host backups are outside the service boundary and may retain historical copies according to host policy.

Uninstall retains data by default. On multisite, uninstall clears scheduled tasks and plugin capabilities on every site, including when LlamaHire was network activated. When a network owner explicitly defines `LLAMAHIRE_REMOVE_DATA` as `true`, that full removal also runs independently for every site. LlamaHire deletes referenced local, VIP, and handled custom-driver resumes before dropping application records, then removes job posts, taxonomy terms, plugin-owned employer metadata and roles, tables, options, and rate-limit transients. Taxonomy cleanup does not depend on the inactive plugin runtime having registered its taxonomies. WordPress user accounts and unrelated Media Library items are retained. If any private resume cannot be removed safely, that site's database records and storage tokens are retained so cleanup can be retried without orphaning the file.

Lifecycle observation hooks receive sanitized IDs and aggregate results, never candidate content or private storage tokens:

- `llamahire_retention_cleanup_completed`
- `llamahire_application_erased`
- `llamahire_application_resume_deleted`
- `llamahire_application_resume_replaced`

LlamaHire also registers with WordPress's native personal-data tools under the `llamahire-applications` exporter/eraser ID. The exporter returns each exact-email application as a separate item and includes stored candidate, application, hiring-note, resume-filename, and notification fields. It never returns the private resume token or filesystem path. The eraser processes bounded exact-email batches through this lifecycle service, so WordPress reports an item as retained when its private resume cannot be safely removed.

### Public submission defenses

Submission keys are scoped to the job and normalized applicant email. Multiple candidates can safely submit the same cached form, while retries by the same applicant remain idempotent. Legacy unscoped keys are recognized only for their original job and applicant.

Free applies a honeypot, idempotency key, per-client limit, and per-job limit before accepting a public application upload. Local attempt limits are consumed before an enabled anti-spam provider is contacted, so rejected provider tokens cannot amplify unmetered outbound requests. Raw client addresses are not stored; the transient key uses a keyed hash. Counter updates are serialized with short ownership-token locks, and a request that contends for an active counter lock fails closed rather than sharing an allowance. The defaults are five attempts per client and 100 attempts per job per hour. Hosts and Pro may tune these controls with:

- `llamahire_submission_rate_limit` — per-client count; return `0` to disable this layer.
- `llamahire_job_submission_rate_limit` — aggregate per-job count; return `0` to disable this layer.
- `llamahire_submission_rate_window` — window in seconds, with a one-minute minimum.

These application-layer limits complement, rather than replace, host or edge rate limiting for high-traffic sites.

### Notification service

`preview( array $application, $job_id ): array` composes the configured plain-text employer and candidate messages without sending them. `application_received( array $application, $job_id, array $channels = array( 'employer', 'candidate' ) ): array` attempts the requested messages after persistence. Its result contains `employer` and `candidate` booleans plus sanitized `error_codes`. Passing only the missing channel allows a retry without resending an email that already succeeded. `test_delivery( $to ): array` sends a candidate-free diagnostic message and returns a boolean plus sanitized error codes.

Core templates support `{candidate_name}`, `{job_title}`, `{site_name}`, `{site_url}`, and `{applications_url}`. Messages remain plain text and sender headers are built only from sanitized settings. Diagnostic state stores the test time, boolean outcome, and sanitized codes; it does not retain the test recipient, mail-server response, or candidate content.

Related observation hooks:

- `llamahire_before_application_notifications`
- `llamahire_application_notifications_sent`

These hooks are for additive behavior and observability. A failed message does not roll back or duplicate the stored application. Administrators can see failed or partial delivery and retry only missing channels.

### Schema builder

`build( $job_id ): array` returns one `JobPosting` entity for an eligible, open, schema-ready job or an empty array when markup must not be emitted. Physical and hybrid jobs need a locality and two-letter country code. Fully remote jobs need at least one eligible applicant country. The builder supports stable identifiers, organization overrides, structured addresses, remote eligibility, employer-provided salary ranges, and `HOUR`, `DAY`, `WEEK`, `MONTH`, or `YEAR` pay units. Optional derived coordinates are stored together with their address hash and are emitted only while that hash still matches the current saved address, including when a cached lookup and an address edit overlap. When both an application deadline and listing expiration exist, `validThrough` uses the earlier saved date and the public facts display both meanings separately.

The public job page renders organization, location, workplace, employment type, salary/pay period, publication date, deadline, and stable reference from the same saved model. Extensions must preserve this visible-page/schema parity.

`llamahire_job_posting_schema` filters the completed entity and receives the schema array and job ID. Extensions are responsible for keeping added or changed values visible on the job page and compliant with Google’s policies.

## Compatibility policy

- API `1.0.0-alpha.*` is intentionally experimental while the Free foundation is extracted and tested.
- Once API 1.0 is declared, documented interfaces, service IDs, hooks, argument order, and behavior follow semantic versioning independently from the plugin version.
- Compatible additions increment the API minor version.
- Deprecations remain functional for at least one documented Free/Pro compatibility window before removal in a future API major version.
- Pro declares a minimum and tested-through Free API version and remains inert with a clear administrator notice when incompatible.
- Free user outcomes never depend on Pro being active.
- Deactivating Pro must leave Free records usable and preserve Pro-owned data for reactivation.

## Public versus internal code

Public:

- Items in `LlamaHire\Contracts`.
- `LlamaHire\Service_IDs` constants.
- Candidate-data constants in `LlamaHire\Capabilities`.
- `Plugin::services()` and `Plugin::api_version()`.
- Hooks explicitly documented in this file.

Internal unless separately documented:

- Concrete classes in `LlamaHire\Services`.
- Database tables and columns.
- Resume paths and storage rules.
- Admin URLs, request payloads, CSS selectors, and block renderer implementation details.
- Static component classes retained during the foundation refactor.

Pro may type-check against public interfaces but must not extend or replace concrete Free classes.

## Capabilities

Candidate data must be authorized through LlamaHire’s granular capabilities, never through `manage_options`, role names, or menu visibility:

| Constant | Capability | Purpose |
|---|---|---|
| `Capabilities::VIEW_APPLICATIONS` | `llamahire_view_applications` | View candidate lists and records |
| `Capabilities::MANAGE_APPLICATIONS` | `llamahire_manage_applications` | Change statuses and private notes |
| `Capabilities::EXPORT_APPLICATIONS` | `llamahire_export_applications` | Export candidate data |
| `Capabilities::DOWNLOAD_RESUMES` | `llamahire_download_resumes` | Download protected resumes |
| `Capabilities::RETRY_NOTIFICATIONS` | `llamahire_retry_notifications` | Retry undelivered application emails |
| `Capabilities::ERASE_APPLICATIONS` | `llamahire_erase_applications` | Permanently erase applications or private resumes |

Jobs use WordPress meta-cap mapping with the singular `llamahire_job` and plural `llamahire_jobs` capability types. Primitive capabilities include `edit_llamahire_jobs`, `publish_llamahire_jobs`, the private/published/others edit and delete variants, plus dedicated department and job-type term capabilities. Administrators can manage job types; employer accounts can assign existing job types without creating or changing the operator's shared vocabulary.

REST writes to featured status and listing expiration require `edit_others_llamahire_jobs`. Author-scoped metadata updates preserve those fields when omitted and reject attempts to change or delete them before saving the post.

## Employer registration hooks

The Free registration flow owns account creation, email verification, and the restricted Employer role. Extensions may tune its bounded abuse baseline with `llamahire_employer_registration_ip_limit` and `llamahire_employer_registration_email_limit`; each filter receives the default hourly request count. Returning `0` disables only that application-layer limit and should be paired with equivalent host or edge enforcement.

Observation hooks expose numeric user IDs and delivery outcomes, never passwords or verification tokens:

- `llamahire_employer_verification_sent( bool $result, int $user_id )`
- `llamahire_employer_operator_notification_sent( bool $result, int $user_id, bool $approved )`
- `llamahire_employer_account_approved( int $user_id, bool $notification_result )`

Registration status, token hashes, policy versions, and company metadata are renderer internals rather than extension contracts. Extensions should observe the hooks or check the documented Employer role and job capabilities instead of reading those meta keys.

Public employer registration and candidate applications can optionally use Cloudflare Turnstile or Google reCAPTCHA. Provider verification requires a successful response for the WordPress site's exact hostname; Turnstile also requires the expected form action. Domain-mapped and staging installations may add normalized hostnames with `llamahire_anti_spam_allowed_hostnames`. The `llamahire_anti_spam_pre_verify` filter receives `null`, the context (`employer_registration` or `job_application`), and the configured provider (`turnstile` or `recaptcha`). Return `true` only after an equivalent trusted verification, return `false` to block the request, or leave the value `null` to use LlamaHire's configured provider. Site and secret keys are private settings and must not be read as an extension contract.

Administrators receive the full set during installation and schema maintenance. The built-in Hiring Manager role receives site-wide job and candidate workflow capabilities, but not site settings or permanent candidate erasure. The built-in Employer role receives only author-scoped job and candidate capabilities after verification and approval and is routed through the frontend My Jobs workflow. Employers do not receive the core primitive capabilities for editing or deleting published jobs; LlamaHire's ownership boundary authorizes the moderated frontend actions instead. Menu visibility and frontend-only routing are experience boundaries; capabilities plus ownership checks remain authoritative. Other roles receive no hiring access by default. Extensions can grant only the capabilities their users need.

Pro-specific operations require Pro-owned capabilities. Pro may require a Free capability in addition to its own when an operation reads or changes Free-owned candidate data.

## Schema and capability versions

`LLAMAHIRE_SCHEMA_VERSION` and `LLAMAHIRE_CAPABILITIES_VERSION` are internal maintenance versions, separate from both the plugin and public API versions. Free applies idempotent, forward-only schema migrations during activation and ordinary requests, so upgrades do not depend on deactivation/reactivation. Activation registers migration-required taxonomies before running data conversion. Data backfills use bounded keyset batches with persisted cursors and scheduled continuation; a failed record leaves its cursor and schema version unchanged so the complete conversion can retry safely, and the schema version advances only after every batch completes.

Pro must maintain its own schema/capability versions and migration runner for Pro-owned data. It must never update Free’s version options or duplicate Free migrations.

## Contract test

The core smoke command asserts API version, boot timing, service conformance, registry immutability, idempotent application persistence, notification failure/retry state, query behavior, private-storage redaction/health, retention cleanup, manual erasure, and schema generation:

```sh
wp eval-file wp-content/plugins/llamahire/tests/smoke.php
```

The future private Pro repository must run its own compatibility suite against Free `main`, the latest Free release, and the oldest supported Free release.
