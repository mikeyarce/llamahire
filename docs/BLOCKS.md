# LlamaHire block composition contract

Status: experimental in 0.1.0

Last updated: July 31, 2026

## Query-state composition

Job discovery blocks compose through one canonical, server-owned URL query contract. They do not require a specific parent block and remain functional when JavaScript is unavailable.

| Parameter | Meaning | Sanitization |
| --- | --- | --- |
| `job_search` | Title/content keyword | Plain text |
| `department` | One or more department/job-category term slugs | WordPress keys |
| `employment_type` | One or more operator-managed job-type term slugs | Registered job types |
| `workplace` | One or more of `remote`, `hybrid`, or `onsite` | Known allowlist |
| `location` | One or more available physical job locations | Plain text against normalized location metadata |
| `featured` | Featured roles only | Exact value `1` |
| `job_page` | Results page | Positive integer |

`Job Search` preserves active filter parameters. `Job Filters` preserves the active search parameter. Both omit `job_page` when submitted so a changed query returns to the first page. `Jobs Directory` consumes the complete state and owns result counts, pagination, clear actions, cards, and empty states.

JavaScript-enhanced URLs store multiple categorical values as a comma-separated value under the existing parameter, for example `employment_type=full_time,part_time`. Locations use a pipe separator so commas inside labels remain intact, for example `location=Vancouver%2C%20BC%2C%20CA%7CToronto%2C%20ON%2C%20CA`. The no-JavaScript checkbox form may submit the equivalent PHP-style repeated array parameters. The server accepts both forms and treats values within one category as an OR query while categories continue to combine with AND.

Job types are stored in the `llamahire_job_type` taxonomy and are managed by the site operator. Standard slugs such as `full_time` are translated to Google's corresponding structured-data value; custom operator-defined types remain visible and filterable but are omitted from `employmentType` when Google does not define a matching value. The existing `llamahire_department` taxonomy is labeled Departments in company mode and Job Categories in job-board mode; its internal name remains stable for compatibility.

The all-in-one Jobs Directory remains supported through its `showFilters` attribute. The supplied Careers Page pattern demonstrates composition with standalone Search and Filters blocks followed by a Directory with its internal controls disabled.

## Open-job RSS

Every Jobs Directory includes a “Subscribe to these jobs (RSS)” link. The `llamahire-jobs` feed uses the same sanitized `job_search`, `department`, `employment_type`, `workplace`, `location`, and `featured` state as the visible directory, including fixed department and featured-only block contexts. It deliberately omits pagination and returns at most the 50 newest matching listings.

The feed queries only published jobs that pass the canonical open-job rules. Draft, pending, closed, application-deadline-ended, listing-expired, trashed, and deleted jobs are excluded. WordPress also receives an RSS discovery link in the document head so browsers and feed readers can discover the filtered feed without depending on JavaScript.

## Progressive enhancement boundary

The baseline contract is ordinary semantic GET forms and server-rendered results. URLs are shareable, reloadable, crawlable, and usable without JavaScript. Search and filter controls retain visible submit buttons in that baseline.

When JavaScript is available, the three discovery blocks use the WordPress Interactivity API and client-side router as a progressive enhancement:

- department/job category, job type, and location-type disclosure menus accept multiple checkbox selections and update immediately;
- location is a searchable multi-select populated from physical locations on currently open jobs; choosing an option clears the search field and adds an individually removable active-filter chip;
- featured updates immediately;
- keyword fields update after a short typing delay;
- only the server-rendered Jobs Directory region is replaced;
- active values are shown as individually removable chips;
- the canonical query string and browser history remain shareable; and
- focus returns to the control that initiated the update while the result count remains a polite live region.

The enhancement reads and writes the same server-owned query parameters. The hidden fallback button is revealed automatically when the module cannot run, links—including the filtered RSS subscription—remain real links, and a full page load always produces the same result state.

## Query metadata

Frequently filtered job values are synchronized to dedicated post-meta keys rather than queried inside the serialized job object:

- `_llamahire_workplace`
- `_llamahire_employment_type`
- `_llamahire_location`
- `_llamahire_featured`
- `_llamahire_closed`
- `_llamahire_deadline`

Schema migration 6 backfills the additional employment and location keys for existing jobs.

## Job context

Query state and individual-job context are separate contracts. Search and filter state belongs in the URL. Reusable job-card/detail children consume `llamahire/jobId` through WordPress block context supplied by a query/container block; authors do not enter post IDs manually.

`llamahire/job-card` and `llamahire/single-job-details` declare `usesContext: ["llamahire/jobId"]`. Server-rendered job collections supply that value for each result. The Jobs Directory and Featured Jobs blocks both render through the same contextual Job Card implementation, so card markup, open-job rules, and display settings cannot drift between collections.

WordPress block metadata can map parent attributes into context, but a query result's post ID is runtime data rather than a saved parent attribute. LlamaHire therefore supplies the per-result context when it instantiates each server-rendered child block. Future collection blocks should reuse that boundary rather than adding a public `jobId` attribute to presentation blocks.

A standalone Job Card resolves the current job on a single-job template. Elsewhere, the editor explains that a collection must supply the job and the front end emits no orphaned placeholder. Closed, expired, draft, and deleted jobs never produce card markup.

Single Job Details resolves the same context and exposes organization, location, location type, employment type, salary, posted date, application deadline, listing expiration, and job reference as semantic definition-list rows. Application deadline and listing expiration remain separate facts, while `JobPosting.validThrough` uses the earlier date. Each group can be hidden independently, and authors can choose a full street address or the more compact city/region/country label. Details remain visible on closed historical job pages; unlike a discovery card, they do not imply that applications are open.

The facts panel uses a text-first divided-cell treatment with compact uppercase labels and no decorative icons. It wraps only the rows that contain values. Remaining facts expand to fill each row, so incomplete drafts and jobs without salary, deadline, or reference data do not leave reserved empty columns.

Existing job posts still receive the details panel automatically. When an author adds Single Job Details to the job content, the compatibility fallback detects the block and does not inject a duplicate panel.

## Theme inheritance

Job Search, Job Filters, and Jobs Directory expose WordPress-native text/background, typography, spacing, and border supports; Jobs Directory also exposes link color and block-gap support. Job Card exposes WordPress-native text, background, link, typography, spacing, and border supports. Featured Jobs exposes alignment, text/background, typography, and spacing supports. Single Job Details exposes alignment, text/background, typography, spacing, and border supports. Plugin CSS provides low-specificity defaults so theme presets and block support styles can override them without custom LlamaHire color fields. Themes can refine the discovery controls through the `--llamahire-control-*`, `--llamahire-chip-*`, `--llamahire-focus-color`, and `--llamahire-filter-gap` custom properties without replacing their interaction behavior.

On WordPress 6.7 and newer, LlamaHire registers native `single-llamahire_job`, `archive-llamahire_job`, and `taxonomy-llamahire_department` block templates. They use the active block theme's header, footer, typography, spacing presets, base color, and contrast color. Site owners can edit them in Appearance → Editor → Templates, while block themes can override them with files of the same standard slug in their own `/templates` directory.

Classic themes and WordPress 6.5–6.6 continue through WordPress's normal template hierarchy. A theme can provide `single-llamahire_job.php`, `archive-llamahire_job.php`, or `taxonomy-llamahire_department.php`; otherwise LlamaHire augments the theme's existing single-job content without replacing its header, footer, or layout wrappers. This avoids assuming theme-specific container markup.

The supplied archive templates deliberately use LlamaHire's server-rendered discovery blocks instead of a generic post loop, so draft, closed, and expired jobs cannot reappear as open listings. Department archive directories also inherit the currently queried department automatically.

## Authoring variations

LlamaHire registers focused variations through WordPress's server-side variations API:

- Jobs List: Jobs Directory without built-in controls.
- Location Filters: a reduced location and location-type filter set for distributed teams.
- Compact Featured Jobs: concise cards without excerpts or badges.
- Essential Job Details: primary role facts without organization, posted date, or internal reference.

Variations only provide useful starting attributes. They use the same underlying blocks and remain fully editable after insertion.

## Patterns

The LlamaHire pattern category contains Careers Page, Careers Hero, Featured Jobs Section, and Department Landing Page. Patterns are registered by the plugin, remain ordinary editable block markup, and use theme-inheriting core blocks around LlamaHire's dynamic blocks.

Jobs Directory has an optional fixed `department` attribute for department landing pages. Authors select the taxonomy term by name in the editor. On the front end, the fixed department is preserved as a hidden value while visitors can use the remaining controls; it is not presented as a removable URL filter.
