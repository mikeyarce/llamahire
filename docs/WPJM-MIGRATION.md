# WP Job Manager migration

LlamaHire includes a first-pass, same-site WP-CLI importer for WP Job Manager
core listings. It copies jobs into **drafts for review**, leaving WPJM's posts,
users, metadata, taxonomies, settings, pages, and URLs untouched. It also works
when WPJM is deactivated, provided its data remains on the current site.

## Preview and import

Back up the site first. Select a WordPress user with board-wide job editing
and category/type management capabilities using WP-CLI's `--user` option.

From the Studio site root:

```sh
studio wp llamahire import wpjm --user=admin
studio wp llamahire import wpjm --user=admin --commit
```

On other WordPress installations, use their normal WP-CLI entry point.
Omitting `--commit` previews without writing anything. Output contains source
IDs, destination IDs, results, and review codes; it does not print applicant
data, employer email addresses, or application targets.

Each invocation scans up to 100 source records, ordered by ID. Use `--limit`
(1–500) to change the batch size. Continue with the reported `--after` cursor.
An empty batch means the scan has finished. When importing, start with the same
cursor used for preview; the preview's next cursor is for previewing another
batch, not committing the batch just previewed.

Retries skip copies already linked to a source ID, including trashed copies,
and preserve edits made to imported jobs. A failure stops the batch; retry with
the original cursor. A commit lock prevents two importer commands from creating
duplicates simultaneously. If a process dies, confirm it is no longer running
before removing `llamahire_wpjm_import_lock` with the WordPress Options API or
WP-CLI. Drafts created by an interrupted import are marked incomplete; the
next run stops for review instead of duplicating or overwriting them. To retry
an incomplete copy, review and permanently delete that destination draft first.
Newly created taxonomy terms may remain after a failed record.

## Mapping and review

| WPJM source | LlamaHire copy |
| --- | --- |
| Title, description, excerpt, slug, publication date | Draft job fields; content uses WordPress HTML sanitization |
| `post_author` | Same existing WordPress user; no password or role changes |
| Company name, tagline, website, logo URL | Per-job organization fields |
| Featured image | Same local attachment |
| Location and stored geolocation address | Location and structured address; no inferred board address |
| Remote position | Remote workplace; otherwise onsite |
| Filled or expired | Closed job |
| Featured | Not copied; drafts start unfeatured, with `featured_requires_review` when the source was featured |
| Expiry date | Listing expiration |
| Application email or HTTP(S) URL | External email or external URL application method |
| Numeric salary with known currency and period | Salary minimum, currency, period |
| Job type | First source type, ordered by term ID |
| Job categories | Flat LlamaHire department/category terms |

Terms reuse destination slugs when present and otherwise copy names and
descriptions. Existing destination terms are not overwritten. The current
LlamaHire model supports one primary type; multiple types are flagged for review.
Category hierarchy and WPJM term metadata are not imported.

Review codes identify multiple types, invalid expiry dates, unsupported or
missing application routing, salaries that cannot be mapped confidently, and
owners without LlamaHire job-editing access. Jobs with missing/guest owners stop
the batch until an existing owner is assigned to the WPJM source. Source status
and review codes are stored as private provenance on the draft. All records
require review before publication, including pending-payment and preview jobs.
Trash and auto-draft sources are excluded.

Featured status requires fresh board approval in LlamaHire. Legacy `_featured`
metadata never enables promotion on a new draft. Repeated imports skip existing
copies and preserve any subsequent board-manager decision.

This pass preserves employer ownership; it does not migrate employer account
permissions, company profile user metadata, registration approval, or settings.
An operator must review access and configure LlamaHire's employer workflow.
It does not import Applications or Resume Manager records, notes, resumes,
paid packages, subscriptions, alerts, bookmarks, custom fields, page shortcodes,
or redirects. Those require separate adapters or a cutover workflow. Core field
mapping follows [WPJM's documented storage](https://wpjobmanager.com/document/developer-reference/plugins/core-wpjm-database-entries/).

## Validation

Run focused integration checks in the disposable environment:

```sh
npm run env:start
npm run test:import:wpjm
```

Coverage includes preview behavior, deactivated source taxonomies, mapping,
content preservation, synchronized discovery fields, source preservation,
repeat and trashed-copy behavior, review warnings, and guest ownership.

### WPJM Playground demo snapshot

```sh
npm run test:import:wpjm:playground
```

The runner fetches [the supplied WPJM blueprint](https://wordpress.org/plugins/wp-json/plugins/v1/plugin/wp-job-manager/blueprint.json?rev=3679700&lang=en_US)
and its `playground-5.zip` snapshot, installs real WPJM and a temporary package
of the current LlamaHire code, and migrates the existing jobs in a fresh local
Playground. It uses the blueprint's PHP 8.2 and latest WordPress preferences.
No Studio or shared Docker data is used. Network access and Python 3 are required.

The installed Playground CLI cannot run this 2024 archive's original
`importWordPressFiles` step: it fails while copying Finder metadata and then the
database directory. The test removes only `__MACOSX` and `.DS_Store` archive
metadata, verifies the SQLite file is byte-for-byte identical, and mounts the
extracted WordPress content before boot. It keeps the blueprint's WPJM install
and login steps. This tests the supplied dataset and actual migration in
Playground, rather than the browser's original archive-loading path.

Validated on October 3, 2026 with PHP 8.2.32, WordPress 7.1.2, and WPJM 2.4.7:

- 173 source listings: 172 published and one pending payment.
- 173 draft copies, in batches of 100 and 73.
- All original job posts, metadata, and taxonomy relationships preserved.
- All 173 external application routes retained; one numeric salary mapped.
- A repeated full import created zero duplicates and sent zero migration emails.
- 2,084 assertions passed, including content, company/location fields, ownership,
  dates, taxonomy assignments, featured images, and synchronized discovery fields.

The runner prints the temporary directory containing the upstream blueprint,
original snapshot, test blueprint, run log, and aggregate `report.json`. The
current upstream blueprint endpoint and plugin download may change over time;
the runner stops if the expected snapshot URL or listing count changes.
