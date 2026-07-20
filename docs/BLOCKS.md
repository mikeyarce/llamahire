# LlamaHire block composition contract

Status: experimental in 0.1.0  
Last updated: July 20, 2026

## Query-state composition

Job discovery blocks compose through one canonical, server-owned URL query contract. They do not require a specific parent block and remain functional when JavaScript is unavailable.

| Parameter | Meaning | Sanitization |
| --- | --- | --- |
| `job_search` | Title/content keyword | Plain text |
| `department` | Department term slug | WordPress key |
| `employment_type` | Google Jobs employment code | Known allowlist |
| `workplace` | `remote`, `hybrid`, or `onsite` | Known allowlist |
| `location` | City, region, country, or eligible remote country | Plain text against normalized location metadata |
| `featured` | Featured roles only | Exact value `1` |
| `job_page` | Results page | Positive integer |

`Job Search` preserves active filter parameters. `Job Filters` preserves the active search parameter. Both omit `job_page` when submitted so a changed query returns to the first page. `Jobs Directory` consumes the complete state and owns result counts, pagination, clear actions, cards, and empty states.

The all-in-one Jobs Directory remains supported through its `showFilters` attribute. The supplied Careers Page pattern demonstrates composition with standalone Search and Filters blocks followed by a Directory with its internal controls disabled.

## Progressive enhancement boundary

The baseline contract is ordinary semantic GET forms and server-rendered results. URLs are shareable, reloadable, crawlable, and usable without JavaScript. A future Interactivity API layer may update results in place, but it must preserve the same parameters, URLs, focus behavior, announcements, and server-rendered fallback.

## Query metadata

Frequently filtered job values are synchronized to dedicated post-meta keys rather than queried inside the serialized job object:

- `_llamahire_workplace`
- `_llamahire_employment_type`
- `_llamahire_location`
- `_llamahire_featured`
- `_llamahire_closed`
- `_llamahire_deadline`

Schema migration 6 backfills the additional employment and location keys for existing jobs.

## Job context for the next slice

Query state and individual-job context are separate contracts. Search and filter state belongs in the URL. Reusable job-card/detail children will consume `llamahire/jobId` through WordPress block context supplied by a query/container block; authors will not enter post IDs manually. The next Milestone 2 slice will formalize that context in block metadata while implementing Job Card and Featured Jobs.
