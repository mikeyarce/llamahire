# LlamaHire block composition contract

Status: experimental in 0.1.0

Last updated: July 21, 2026

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

## Job context

Query state and individual-job context are separate contracts. Search and filter state belongs in the URL. Reusable job-card/detail children consume `llamahire/jobId` through WordPress block context supplied by a query/container block; authors do not enter post IDs manually.

`llamahire/job-card` and `llamahire/single-job-details` declare `usesContext: ["llamahire/jobId"]`. Server-rendered job collections supply that value for each result. The Jobs Directory and Featured Jobs blocks both render through the same contextual Job Card implementation, so card markup, open-job rules, and display settings cannot drift between collections.

WordPress block metadata can map parent attributes into context, but a query result's post ID is runtime data rather than a saved parent attribute. LlamaHire therefore supplies the per-result context when it instantiates each server-rendered child block. Future collection blocks should reuse that boundary rather than adding a public `jobId` attribute to presentation blocks.

A standalone Job Card resolves the current job on a single-job template. Elsewhere, the editor explains that a collection must supply the job and the front end emits no orphaned placeholder. Closed, expired, draft, and deleted jobs never produce card markup.

Single Job Details resolves the same context and exposes organization, location, workplace, employment type, salary, posted date, application deadline, and job reference as semantic definition-list rows. Each row can be hidden independently, and authors can choose a full street address or the more compact city/region/country label. Details remain visible on closed historical job pages; unlike a discovery card, they do not imply that applications are open.

Existing job posts still receive the details panel automatically. When an author adds Single Job Details to the job content, the compatibility fallback detects the block and does not inject a duplicate panel.

## Theme inheritance

Job Card exposes WordPress-native text, background, link, typography, spacing, and border supports. Featured Jobs exposes alignment, text/background, typography, and spacing supports. Single Job Details exposes alignment, text/background, typography, spacing, and border supports. Plugin CSS provides low-specificity defaults so theme presets and block support styles can override them without custom LlamaHire color fields.

On WordPress 6.7 and newer, LlamaHire registers native `single-llamahire_job`, `archive-llamahire_job`, and `taxonomy-llamahire_department` block templates. They use the active block theme's header, footer, typography, spacing presets, base color, and contrast color. Site owners can edit them in Appearance → Editor → Templates, while block themes can override them with files of the same standard slug in their own `/templates` directory.

Classic themes and WordPress 6.5–6.6 continue through WordPress's normal template hierarchy. A theme can provide `single-llamahire_job.php`, `archive-llamahire_job.php`, or `taxonomy-llamahire_department.php`; otherwise LlamaHire augments the theme's existing single-job content without replacing its header, footer, or layout wrappers. This avoids assuming theme-specific container markup.

The supplied archive templates deliberately use LlamaHire's server-rendered discovery blocks instead of a generic post loop, so draft, closed, and expired jobs cannot reappear as open listings. Department archive directories also inherit the currently queried department automatically.

## Authoring variations

LlamaHire registers focused variations through WordPress's server-side variations API:

- Jobs List: Jobs Directory without built-in controls.
- Location & Work Style Filters: a reduced filter set for distributed teams.
- Compact Featured Jobs: concise cards without excerpts or badges.
- Essential Job Details: primary role facts without organization, posted date, or internal reference.

Variations only provide useful starting attributes. They use the same underlying blocks and remain fully editable after insertion.

## Patterns

The LlamaHire pattern category contains Careers Page, Careers Hero, Featured Jobs Section, and Department Landing Page. Patterns are registered by the plugin, remain ordinary editable block markup, and use theme-inheriting core blocks around LlamaHire's dynamic blocks.

Jobs Directory has an optional fixed `department` attribute for department landing pages. Authors select the taxonomy term by name in the editor. On the front end, the fixed department is preserved as a hidden value while visitors can use the remaining controls; it is not presented as a removable URL filter.
