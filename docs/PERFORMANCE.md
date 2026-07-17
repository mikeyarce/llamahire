# Backend performance review

Reviewed: July 17, 2026
Plugin version: 0.1.0
Local database: SQLite 3.53.3 through the WordPress SQLite integration
Runtime: PHP 8.5.5, WP-CLI

## Scope

The review covered:

- Application list pagination, status/job filters, exact-email search, dashboard counts, recent applicants, and CSV iteration.
- Job directory default, workplace, featured, and keyword queries.
- Dashboard open-job counting.
- Query count, elapsed time, bounded memory behavior, storage-path exposure, and likely N+1 patterns.
- Index alignment with actual query shapes.

Benchmarks are disposable and remove their synthetic data:

```sh
wp eval-file wp-content/plugins/llamahire/tests/performance/applications.php
wp eval-file wp-content/plugins/llamahire/tests/performance/jobs.php
```

Local timings are comparative evidence, not production service-level guarantees. MySQL/MariaDB benchmarks remain a release gate.

## Findings and fixes

### Application reads

Admin screens previously assembled direct, unpaginated SQL and loaded as many as 200 full candidate records. CSV export loaded every application into memory.

Implemented:

- A public bounded application-query service.
- Twenty rows per admin page, with a hard service cap of 100.
- Stable `created_at DESC, id DESC` ordering.
- Separate count and bounded item queries.
- One-query status aggregation.
- One-query recent-applicant lookup without an unnecessary total count.
- Exact indexed email matching when the search input is a complete email address.
- Five-hundred-row keyset batches for export instead of an unbounded in-memory result.
- Job-title joins in list, dashboard, and export queries to prevent per-row post lookups.
- No resume filesystem token/path in public query or repository results.

### Application indexes

Migration 3 added indexes matching current and upcoming operations:

- `job_created (job_id, created_at, id)`
- `job_status_created (job_id, status, created_at, id)`
- `status_created (status, created_at, id)`
- `job_email (job_id, email)`

The last index supports exact per-job email lookup. Migration 4 separately adds a unique nullable `submission_key`, allowing duplicate public requests to converge on one application without affecting imported legacy rows.

### Job dashboard N+1

The original dashboard loaded every published job ID and called `get_post_meta()` once per uncached job. With 500 jobs, the open count used 471 queries and approximately 244 ms.

Implemented:

- Queryable, dual-written job state fields for workplace, featured, closed, and deadline.
- An idempotent migration that backfills only missing query fields in bounded insert batches.
- SQL-level availability filtering for manual closure and deadlines.
- A direct found-row open count instead of loading every job and its metadata.

After the change, the same 500-job count used 2 queries and approximately 2.8 ms.

### Directory correctness and query shape

The original directory queried serialized metadata with `LIKE`, selected the page limit, and then discarded closed jobs in PHP. Filtered pages therefore returned only 6–11 cards when 12 valid matches existed.

Implemented:

- Exact comparisons against normalized workplace/featured fields.
- Closed/deadline conditions inside the WordPress query.
- The requested 12 valid cards are now returned by the benchmark for default, remote, and featured views.

## Final observed results

### 10,000 applications

| Operation | Queries | Time |
|---|---:|---:|
| First page, 20 rows plus total | 2 | 8.4 ms |
| Status-filtered page | 2 | 1.7 ms |
| Deep page 400 | 2 | 2.5 ms |
| Exact email search | 2 | 4.0 ms |
| Counts grouped by status | 1 | 1.2 ms |
| Five recent applications | 1 | 1.4 ms |
| Stream all 10,000 export rows | 21 | 34.5 ms |

The export showed no lasting measured memory increase after batching. Fixture creation is excluded from product performance conclusions.

### 500 jobs

| Operation | Queries | Time | Result |
|---|---:|---:|---:|
| Default directory | 4 | 6.6 ms | 12 cards |
| Remote directory | 3 | 7.1 ms | 12 cards |
| Featured directory | 3 | 6.5 ms | 12 cards |
| Keyword search | 2 | 4.1 ms | 1 card |
| Dashboard open count | 2 | 2.6 ms | 475 open |

These job benchmarks were rerun after the structured Google Jobs model and public location formatting were added. The richer model introduced no query-count regression.

## Remaining risks and release gates

1. Partial name/email search uses a leading-wildcard `LIKE` and therefore scans matching candidate rows. Exact email uses an index. Before 1.0, benchmark partial search with 100,000 applications on MySQL and MariaDB; consider prefix search or a separate search strategy if it exceeds budget.
2. Numbered pages use SQL offsets. Page 400 is fast at 10,000 local rows, but very deep offsets are linear. Move to cursor navigation if the 100,000-row database matrix exceeds budget.
3. Job filters use WordPress postmeta joins. Normalized fields remove serialized scans, but 10,000-job MySQL/MariaDB tests must confirm the joins remain acceptable. A dedicated job index table is an option only if measured evidence requires it.
4. Each paginated application view performs an exact total count. This is necessary for numbered pagination but may become the dominant cost at high volume; cursor pagination would remove it.
5. The local SQLite integration cannot provide representative MySQL query plans through `wpdb`. CI must capture `EXPLAIN` plans on supported MySQL/MariaDB versions and fail on full scans for exact job/status/email paths.
6. Object-cache and no-object-cache environments both need coverage. Correctness and authorization must never depend on a persistent cache.

Initial performance budgets for the production database matrix:

- Application page/filter: fewer than 100 ms database time at 100,000 applications.
- Exact per-job email lookup: fewer than 25 ms at 100,000 applications.
- Dashboard application counts/recent list: fewer than 100 ms combined.
- Open-job count and directory page: fewer than 100 ms at 10,000 jobs.
- Export: bounded memory with batches no larger than 500 rows.
- No request may issue one database query per application or job.
