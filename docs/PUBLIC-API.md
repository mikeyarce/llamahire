# LlamaHire Free public API

API version: `1.0.0-alpha.4`
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

```php
add_action(
	'llamahire_register_services',
	static function ( $services, $api_version ) {
		if ( version_compare( $api_version, '1.0.0-alpha.4', '<' ) ) {
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
		if ( version_compare( $free->api_version(), '1.0.0-alpha.4', '<' ) ) {
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
| `Service_IDs::NOTIFICATIONS` | `Contracts\Notification_Service` | Free |
| `Service_IDs::RESUME_STORAGE` | `Contracts\Resume_Storage` | Free |
| `Service_IDs::SCHEMA_BUILDER` | `Contracts\Schema_Builder` | Free |

### Application repository

The repository owns application-record persistence:

- `create( array $application ): int|WP_Error`
- `create_once( array $application ): array|WP_Error`
- `find( $application_id ): ?object`
- `update( $application_id, array $changes ): bool|WP_Error`
- `delete( $application_id ): bool`
- `record_notification_result( $application_id, array $result ): bool|WP_Error`

`create_once()` requires a UUID submission key and returns an `id` plus a `created` boolean. Reusing the same key converges on the original application, including concurrent requests through the database unique constraint. Extensions that receive public submissions should use this operation and send side effects only when `created` is true.

`record_notification_result()` persists channel-level success, attempt count, aggregate status, and sanitized error codes. It never stores mail error messages or candidate content. Only `status` and `notes` are currently public update fields. Extensions must use this contract rather than relying on table names or direct SQL. Resume lifecycle operations belong to the separate resume-storage contract.

### Application query

The bounded query service provides paginated `search()`, grouped `counts()`, bounded `recent()`, and batched `export_rows()` operations. It never exposes the private resume token/path. Extensions must not query the applications table directly.

### Resume storage

The resume service owns validation, opaque storage tokens, deletion, availability checks, authorized streaming, and storage health. Extensions may call `has_resume()` after checking application-view permission, but must use `stream()` only after checking `Capabilities::DOWNLOAD_RESUMES` and a request nonce.

Storage tokens and filesystem paths are internal even when passed between Free services. Pro must store application IDs, not resume paths.

### Notification service

`application_received( array $application, $job_id, array $channels = array( 'employer', 'candidate' ) ): array` attempts the requested messages after persistence. Its result contains `employer` and `candidate` booleans plus sanitized `error_codes`. Passing only the missing channel allows a retry without resending an email that already succeeded.

Related observation hooks:

- `llamahire_before_application_notifications`
- `llamahire_application_notifications_sent`

These hooks are for additive behavior and observability. A failed message does not roll back or duplicate the stored application. Administrators can see failed or partial delivery and retry only missing channels.

### Schema builder

`build( $job_id ): array` returns one `JobPosting` entity for an eligible, open, schema-ready job or an empty array when markup must not be emitted. Physical and hybrid jobs need a locality and two-letter country code. Fully remote jobs need at least one eligible applicant country. The builder supports stable identifiers, organization overrides, structured addresses, remote eligibility, employer-provided salary ranges, and `HOUR`, `DAY`, `WEEK`, `MONTH`, or `YEAR` pay units.

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

Jobs use WordPress meta-cap mapping with the singular `llamahire_job` and plural `llamahire_jobs` capability types. Primitive capabilities include `edit_llamahire_jobs`, `publish_llamahire_jobs`, the private/published/others edit and delete variants, plus dedicated department term capabilities.

Administrators receive the full set during installation and schema maintenance. No other role receives hiring access by default. A future setup flow may create or configure hiring roles, but extensions can already grant only the capabilities their users need.

Pro-specific operations require Pro-owned capabilities. Pro may require a Free capability in addition to its own when an operation reads or changes Free-owned candidate data.

## Schema and capability versions

`LLAMAHIRE_SCHEMA_VERSION` and `LLAMAHIRE_CAPABILITIES_VERSION` are internal maintenance versions, separate from both the plugin and public API versions. Free applies idempotent, forward-only schema migrations during activation and ordinary requests, so upgrades do not depend on deactivation/reactivation.

Pro must maintain its own schema/capability versions and migration runner for Pro-owned data. It must never update Free’s version options or duplicate Free migrations.

## Contract test

The core smoke command asserts API version, boot timing, service conformance, registry immutability, idempotent application persistence, notification failure/retry state, query behavior, private-storage redaction/health, and schema generation:

```sh
wp eval-file wp-content/plugins/llamahire/tests/smoke.php
```

The future private Pro repository must run its own compatibility suite against Free `main`, the latest Free release, and the oldest supported Free release.
