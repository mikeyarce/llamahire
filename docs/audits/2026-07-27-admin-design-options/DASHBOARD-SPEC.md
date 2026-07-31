# Mode-aware dashboard specification

Date: July 29, 2026

## Product decision

The LlamaHire dashboard is a decision-and-exception workspace, not a generic
analytics page.

It answers three questions in order:

1. What needs my attention now?
2. What should I do next?
3. Is the part of hiring or job-board operations I am responsible for healthy?

The page uses one shared structure. Its content changes first by site mode and
then by the current user's capabilities and job-ownership scope.

| Context | Site mode | Scope | Primary job |
| --- | --- | --- | --- |
| Company hiring administrator | Company careers site | Organization-wide | Review candidates and keep hiring moving |
| Job-board operator | Community job board | Board-wide | Moderate listings and keep the marketplace healthy |
| Employer | Community job board | Owned jobs only | Manage listings and review applications to owned jobs |

The employer experience should ultimately live in the front-end employer portal.
Until that portal includes applications and activity, any WordPress-admin
dashboard shown to an Employer account must use the same author-scoped data and
must not reveal board-wide totals, other employers, or other employers'
applications.

## Shared page contract

### Header

- Title:
  - Company: `Hiring dashboard`
  - Board operator: `Job board dashboard`
  - Employer: `Employer dashboard`
- One sentence describing the page's purpose.
- One primary action:
  - Company: `Review applications` when new applications exist; otherwise
    `Add new job`.
  - Board operator: `Moderate jobs` when pending submissions exist; otherwise
    `Add new job`.
  - Employer: context-sensitive `Fix listing`, `Review applications`, or
    `Submit a job`, in that priority order.
- Do not add a date-range control until the dashboard contains true
  period-based metrics. Current lifetime workflow totals must not be presented
  as if they were filtered by a date range.

### Section order

1. Primary action or `Needs your attention`
2. Context-specific work or jobs
3. Small health summary
4. Recent activity

The first viewport should contain an actionable item whenever one exists.
Summary counts are context, not the visual headline.

### Priority rules

Display only non-zero work items. Sort them by:

1. Blocked or failed work
2. Work awaiting a user decision
3. Time-sensitive work
4. New work
5. Informational state

Within the same priority, show the oldest unresolved item first. The dashboard
may summarize a queue with a count and up to two examples; the action opens the
complete filtered destination.

Use these severity semantics:

- `Requires action`: a failure or condition that blocks the intended outcome.
- `Needs review`: a human decision is waiting.
- `Due soon`: a deadline is within seven calendar days.
- Neutral: useful context that does not require intervention.

Do not communicate severity with color alone. Every item needs visible text and
an accessible name that includes its count and action.

## Company hiring administrator

### Primary outcome

Help an internal hiring team find unreviewed candidates, continue active reviews,
and keep open jobs able to receive applications.

### Needs your attention

| Priority | Card | Definition | Destination | Empty behavior |
| --- | --- | --- | --- | --- |
| 1 | Email delivery failures | Applications whose notification status is `pending`, `partial`, or `failed` | Applications filtered by email status | Omit when zero |
| 2 | New applications | Applications with status `new` | Applications filtered to `new` | Replace with a quiet `You are caught up on new applications` state only when the entire attention section would otherwise be empty |
| 3 | Jobs expiring soon | Published, open jobs with a deadline from today through seven days from today | Jobs filtered or ordered by deadline | Omit when zero |

The current workflow does not have Interviewing, Offer, Contacted, or
decision-due states. Do not infer cards such as `Candidates waiting for a
decision` from `reviewing`, and do not invent workflow stages for the dashboard.

### Active jobs

Show up to five open jobs, ranked by:

1. Jobs with new applications
2. Earliest deadline
3. Most recently published

Columns:

- Job
- New applications
- Reviewing
- Deadline
- Next step

The next step is:

- `Review {n} applications` when the job has new applications.
- `Continue reviewing {n}` when it has no new applications and at least one
  application in Reviewing.
- `Update deadline` when the deadline is within seven days and there is no
  candidate work.
- `View job` otherwise.

The first implementation can show total applications by job, but separate New
and Reviewing counts require extending the bounded application query service.

### Hiring summary

Show at most four linked values:

- Open jobs
- New
- Reviewing
- Hired

All values are lifetime/current-state totals and must be labelled accordingly.
Do not display Rejected as a headline metric. Do not combine email failures with
candidate pipeline stages.

### Recent activity

Show the five most recent privacy-safe audit events in the current ownership
scope. Prefer application status changes, notification retries, and job
lifecycle events. Candidate names must be resolved from the application record
only after the existing access check; otherwise identify the application by its
privacy-safe record number.

Action: `View all activity`.

### Company empty states

| Condition | Message | Action |
| --- | --- | --- |
| No jobs | `Publish your first opening to start receiving applications.` | `Add new job` |
| Jobs but none open | `No jobs are accepting applications right now.` | `Review jobs` |
| Open jobs and no applications | `Your jobs are open. Share or preview them to make sure candidates can find them.` | `View open jobs` |
| Applications but no attention items | `You are caught up. There are no new applications or delivery problems.` | `View applications` |
| No activity | `Hiring activity will appear here as jobs and applications are updated.` | No button |

## Community job-board operator

### Primary outcome

Help the operator moderate employer submissions, resolve listing and delivery
problems, and understand the current supply of jobs on the board.

### Work queue

| Priority | Card | Definition | Destination | Empty behavior |
| --- | --- | --- | --- | --- |
| 1 | Email delivery failures | Internal applications with notification status `pending`, `partial`, or `failed` | Applications filtered by email status | Omit when zero |
| 2 | Job submissions awaiting moderation | Jobs with post status `pending` | Jobs filtered to pending | Show `No listings are awaiting moderation` only when the entire queue is empty |
| 3 | Listings missing required company details | Pending or published jobs missing organization name or a valid application destination | Filtered job list or readiness view | Omit when zero |
| 4 | Listings expiring soon | Published, open jobs with a deadline from today through seven days from today | Jobs ordered by deadline | Omit when zero |
| 5 | New internal applications | Applications submitted through LlamaHire with status `new` | Applications filtered to `new` | Omit when zero |

Moderation takes precedence over candidate review for the board operator. New
applications remain visible only when the board hosts the application flow.
Externally routed applications must not be counted as internal applications or
presented as unknown zeroes.

### Board health

Show current-state counts:

- Active listings
- Awaiting moderation
- Employers with at least one active listing
- Internal applications received in the last 30 days

The first two counts are available from job posts. The employer and 30-day
application counts require bounded aggregate queries.

Do not show `Hired` as a board-health metric. The board cannot reliably observe
outcomes for external applications.

### Recent board activity

Show up to five events:

- Job submitted or resubmitted
- Job approved
- Changes requested
- Job declined
- Job closed or deleted
- Job owner changed
- Application received only if a new privacy-safe event is added for it

Columns:

- Actor or employer
- Event
- Related job
- Date

The audit log currently records job workflow events and application status
changes, but it does not record an application-received event. Do not fabricate
one from the audit log.

### Board-operator empty states

| Condition | Message | Action |
| --- | --- | --- |
| No listings | `Your job board is ready for its first listing.` | `Add new job` |
| No employers | `Invite or create an Employer account so organizations can submit listings.` | `Add employer` |
| No pending submissions | `No listings are waiting for moderation.` | `View all jobs` |
| No work-queue items | `The board is up to date. There are no moderation, deadline, or delivery issues.` | `View all jobs` |
| No internal applications | `Applications are currently handled outside this site, or none have been received yet.` | `View application settings` |
| No activity | `Board activity will appear here when employers submit or update listings.` | No button |

## Employer

### Placement

The preferred destination is a front-end account dashboard that extends the
existing My Jobs page. It should not require an Employer user to understand
WordPress administration.

If the employer continues to use the WordPress-admin Dashboard and Applications
screens during the MVP, all queries use `Ownership::query_arguments()` and all
links must resolve to author-scoped destinations.

### Needs your attention

| Priority | Card | Definition | Destination | Empty behavior |
| --- | --- | --- | --- | --- |
| 1 | Listings needing changes | Owned jobs returned to `draft` after moderation | Edit owned listing | Omit when zero |
| 2 | Email delivery failures | Owned-job applications with notification status `pending`, `partial`, or `failed` | Scoped Applications filter | Omit when zero |
| 3 | New applications | Owned-job applications with status `new` | Scoped Applications filter | Omit when zero |
| 4 | Listings expiring soon | Owned, published, open jobs expiring within seven days | My Jobs | Omit when zero |
| 5 | Listings awaiting moderation | Owned jobs with post status `pending` | My Jobs | Informational; no operator action implied |

`Draft` alone does not prove that an operator requested changes: employers may
also have ordinary drafts. A reliable `Needs changes` card requires either the
latest `job_changes_requested` audit event or a dedicated moderation state.

### My jobs

Show owned jobs only, ordered by actionable state and then most recent:

1. Changes requested
2. Pending moderation
3. Published and expiring soon
4. Published
5. Closed or ordinary draft

Columns or narrow-screen labels:

- Job
- Publication/moderation status
- Applications
- Deadline
- Contextual action

Actions:

- Changes requested: `Fix listing`
- Pending: `Preview`
- Published with new applications: `Review applications`
- Published without new applications: `View listing`
- Closed: `Preview`

### Employer summary

Show no more than:

- Published jobs
- Awaiting moderation
- New applications
- Total applications

Every number must be scoped to owned jobs. Do not expose active-employer counts,
board-wide application totals, or other marketplace metrics.

### Employer empty states

| Condition | Message | Action |
| --- | --- | --- |
| No jobs | `Submit your first job to reach candidates on this board.` | `Submit a job` |
| Jobs pending, none published | `Your listing is awaiting review by the job-board operator.` | `Preview listing` |
| Published jobs, no applications | `Your listing is live, but no applications have arrived through this site yet.` | `View listing` |
| External application method | `Applications for this job are handled on your website or by email.` | `View listing` |
| No attention items | `Your listings are up to date and there are no new application tasks.` | `View my jobs` |
| No activity | `Activity will appear here when your listings are reviewed or applications are updated.` | No button |

## Role and routing rules

Dashboard selection uses this order:

1. If site mode is Company, show the Company dashboard to users with application
   access.
2. If site mode is Community job board and the user can edit other users' jobs,
   show the Board Operator dashboard.
3. If site mode is Community job board and the user cannot edit other users'
   jobs, show the Employer dashboard with author-scoped data.

Capabilities govern actions independently:

- Without application-view capability, do not render candidate counts or links.
- Without application-management capability, candidate work is read-only.
- Without notification-retry capability, link to the filtered issue list rather
  than showing a Retry action.
- Without job-publishing or moderation capability, never show moderation
  controls.
- Without resume-download capability, do not mention resume availability.

Do not use a role-name check where an existing capability or ownership boundary
can answer the question.

## Responsive behavior

- Use WordPress admin page width and native controls.
- At wide widths, the attention queue may use three columns: issue, examples,
  action.
- Below 782 px, each queue item becomes a labelled card with the action last.
- Job tables become candidate/job-first cards instead of horizontally scrolling.
- The primary action remains near the page title and becomes full width on
  narrow screens.
- Do not rely on icon-only controls.
- Preserve a logical heading order and DOM order matching the visual order.
- Counts linked to filtered destinations must announce both the number and the
  destination, for example `17 new applications — view filtered applications`.

## Current-data implementation boundary

### Available now

- Site mode: Company or Community job board
- Board-wide versus author-scoped ownership
- Open-job count
- Application totals for New, Reviewing, Rejected, and Hired
- Notification-attention total
- Recent applications
- Total applications by job
- Job post status and deadline metadata
- Privacy-safe, author-scoped audit history
- Filterable Applications destination

### Small bounded query additions

- Application status counts grouped by job
- Jobs expiring within seven days
- Pending-job count and examples
- Active-employer count
- Internal applications received within a period
- Listing-readiness count
- Dashboard audit-event type filters

### Do not include until the underlying workflow exists

- Interviewing and Offer stages
- Time-in-stage or candidates waiting for a decision
- Contacted or follow-up-due states
- Hiring conversion rate for externally routed applications
- Revenue, paid listings, packages, or renewals
- Trend charts without a defined time grain and comparable historical query

## Acceptance criteria

- Each context presents a different primary action appropriate to its job.
- The first actionable item is reachable with one activation.
- Every count links to a correctly filtered, permission-safe destination.
- Employer totals and examples contain owned jobs only.
- Board mode never implies that external applications or hires are observable.
- Empty states distinguish no setup, no data, no current work, and unsupported
  external data.
- The page remains understandable without color or icons.
- The narrow layout has no horizontal overflow at 320 CSS pixels.
- No invented workflow state or misleading period comparison appears.
