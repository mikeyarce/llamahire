# DataViews fit review — recruiter operations

Date: July 26, 2026

Implementation status: completed July 27, 2026

## Decision

The bounded Applications-inbox migration is implemented with `@wordpress/dataviews`. Candidate detail and destructive candidate-data workflows remain server-rendered.

DataViews is a strong fit for the Applications inbox because the screen is a structured, paginated dataset whose planned work already includes search, filters, sorting, saved preferences, row actions, and bulk actions. It is a UI layer rather than a query or authorization layer: LlamaHire must continue to own server-side filtering, pagination, capability checks, job ownership, and the shape of candidate data returned to the browser.

## Current filter defect

The live Applications filters fail when the screen is opened through its registered Jobs submenu:

`edit.php?post_type=llamahire_job&page=llamahire-applications`

The GET form preserves `page=llamahire-applications` but drops `post_type=llamahire_job`. Submitting the form therefore navigates to:

`edit.php?page=llamahire-applications&job_id=…&s=…`

WordPress can no longer resolve the custom-post-type submenu and rejects the request. Status, clear, pagination, detail, and test URLs currently use `admin.php?page=llamahire-applications`, so the screen has two inconsistent route shapes.

The Playwright test does not expose the defect because it opens the direct `admin.php` route before filtering. The immediate repair is to establish one canonical Applications URL, preserve all required routing arguments in GET forms and links, and add a browser test that enters through the Jobs > Applications menu route.

This routing bug was fixed by establishing the Jobs submenu `edit.php` route as the canonical Applications URL and removing the inconsistent GET-form route.

## Implementation result

- Added a pinned DataViews production build and WordPress build pipeline.
- Added an ownership-aware `GET /llamahire/v1/applications` endpoint.
- Added server-backed search, pagination, sorting, and filters for candidate name, candidate email, job, workflow status, notification status, and received date.
- Added reload-safe scalar URL state because WordPress admin canonicalization strips array-shaped query parameters on a full reload.
- Kept filtered CSV export synchronized with the active DataViews query.
- Added table/list layouts, view configuration, per-page choices, empty/loading/error states, responsive styling, and RTL output.
- Preserved server-rendered candidate review, resume, notification retry, erasure, and privacy actions.
- Updated browser coverage to enter through the real Jobs submenu route and exercise the DataViews filters and reload behavior.

Validation: 176 smoke checks, all seven Playwright hiring journeys, PHP syntax, `git diff --check`, production asset build, live filtering for every column, and the installable ZIP passed.

The pinned WordPress-specific DataViews bundle is approximately 1.95 MB minified plus 87 KB of CSS. Measure parse/runtime cost on supported WordPress versions during the release-candidate performance pass.

## Proposed Applications architecture

### Keep on the server

- The existing custom applications table.
- `Application_Query` as the query boundary.
- Ownership and capability checks for board administrators and Employers.
- Status allowlists, audit events, export safety, resume authorization, and privacy erasure.
- Candidate-detail actions until they receive their own focused design and security review.

### Add for the DataViews prototype

- A compiled admin React entry point built with `@wordpress/scripts`.
- A pinned `@wordpress/dataviews` dependency imported from `@wordpress/dataviews/wp`.
- A versioned, permission-checked read-only endpoint such as `GET /llamahire/v1/applications`.
- Server parameters for page, per-page, search, status, job, date range, order, and order-by.
- A compact response containing only fields needed by the inbox: application ID, candidate display name and email, job ID and title, workflow status, notification status, and received date.
- Response totals for DataViews pagination.
- URL-backed view state for useful back/forward behavior and shareable filtered views.
- Table and compact-list layouts only. A visual grid is not useful for this workflow.
- Loading, empty, error, and no-JavaScript states.

The endpoint must derive author scope from the authenticated user rather than trusting a client-provided author ID. It must not return cover letters, private notes, phone numbers, resume storage paths, or other candidate-detail data merely to render the inbox.

## Staged implementation

1. Fix the canonical admin route and add regression coverage through the real submenu URL.
2. Add the build pipeline and a fixture-backed DataViews spike to validate bundle loading, WordPress 6.5 compatibility, RTL styles, table/list density, responsive behavior, and accessibility.
3. Add the read-only Applications REST endpoint and connect search, job/status/date filters, sorting, and pagination.
4. Preserve the existing candidate-detail page and navigate to it from a DataViews row action.
5. Persist safe per-user view preferences such as visible columns, column order, density, and page size.
6. Add bulk status changes only after capability, ownership, confirmation, partial-failure, audit-log, and keyboard-flow tests pass.
7. Make filtered export consume the same normalized server query as the current DataViews state.
8. Remove the legacy inbox renderer only after the new screen passes supported-version, no-JavaScript fallback, 10,000-record, mobile, RTL, and assistive-technology checks.

## Where else DataViews may fit

| Screen | Fit | Recommendation |
| --- | --- | --- |
| Applications inbox | Strong | Prototype now in Milestone 4. |
| Activity | Strong | Consider after Applications; the read-only event stream maps well to the activity or table layout. |
| Candidate detail | Partial | Keep the page server-rendered initially. Consider `DataForm` later for status and private notes only. |
| Settings | Partial | Review `DataForm` separately after the inbox prototype; Media Library, page selectors, email previews, and specialized controls still need custom integrations. |
| Jobs admin list | Weak | Keep the native custom-post-type list table and editor integration. |
| Departments | Weak | Keep the native taxonomy screens. |
| Setup wizard | Poor | It is a sequential onboarding flow, not a dataset. |
| Public jobs and Employer My Jobs | Poor | Keep the progressively enhanced front-end experience; do not ship an admin-oriented React data surface to the public UI. |

## Compatibility and release implications

LlamaHire currently supports WordPress 6.5 and has no production JavaScript build pipeline. The plugin therefore should bundle and pin the WordPress-specific DataViews package entry point instead of depending on whichever DataViews version a host WordPress installation happens to expose. Compiled JavaScript, generated asset metadata, LTR/RTL styles, and source/license notices belong in release ZIP validation; `node_modules` does not.

DataViews has changed substantially across WordPress releases, including a major API update in WordPress 7.0. Treat dependency upgrades as intentional product work with screenshots and browser coverage rather than floating to the newest package automatically.

## Success criteria for the spike

- The real Jobs > Applications route and every filter work without an authorization error.
- Search, status, job, date, sort, pagination, and clear/reset state are server-backed and URL-consistent.
- An Employer cannot infer or retrieve another employer's applications by changing REST parameters or IDs.
- The screen remains useful at 320 CSS pixels, at 200% zoom, by keyboard, and with a screen reader.
- Initial and filtered results remain responsive with the 10,000-application fixture.
- The added production asset weight and release process are measured and accepted.
- The prototype provides enough value to replace custom filter, table, pagination, preference, and bulk-selection UI.

## Official references

- [DataViews package reference](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-dataviews/)
- [Using Data Views in plugins](https://developer.wordpress.org/news/2024/08/using-data-views-to-display-and-interact-with-data-in-plugins/)
- [Using DataForm for plugin settings](https://developer.wordpress.org/news/2026/01/how-to-use-dataform-to-create-plugin-settings-pages/)
- [WordPress 7.0 DataViews and DataForm update](https://developer.wordpress.org/news/2026/03/whats-new-for-developers-march-2026/)
