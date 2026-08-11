# LlamaHire end-to-end scenarios

This is the browser-level flow inventory for LlamaHire Free. Its purpose is to
keep every user journey represented without turning browser tests into a slow
duplicate of the PHP contract suite.

## Execution goals

- The `@critical` suite should finish in under 90 seconds locally once the
  disposable WordPress site is running.
- The complete Chromium suite should finish in under four minutes in CI.
- A scenario should normally contain one login and one user outcome.
- Preconditions belong in deterministic WP-CLI fixtures. The browser should
  create data only when creation is the behavior being tested.
- Each independently runnable scenario should own uniquely named users, jobs,
  applications, and files so it can be retried safely.
- Exhaustive sanitization, capability matrices, migration cases, retention,
  notification internals, and storage-driver cases remain in the faster PHP
  suite. Browser tests prove that the corresponding user journey is connected.

The existing workflow uses a serial fixture because later scenarios consume
state created by earlier ones. The first optimization step is the flow tags
below. The next step is to give each group a small fixture factory and split it
into an independent spec; that will allow safe Playwright parallelism.

## Flow tags

| Tag | Product area |
| --- | --- |
| `@critical` | Setup, application, recruiter, and employer happy paths |
| `@setup` | First-run setup and mode selection |
| `@company` | Single-employer careers site |
| `@discovery` | Public search and filtering |
| `@jobs` | Administrator job authoring |
| `@candidate` | Candidate-facing application flow |
| `@applications` | Application creation and lifecycle |
| `@recruiter` | Hiring inbox and candidate review |
| `@employer` | Employer account and owned listings |
| `@job-board` | Multi-employer moderation flow |
| `@settings` | Operational configuration |
| `@notifications` | Email configuration and retry surfaces |
| `@public` | Public templates and blocks |
| `@responsive` | Narrow viewport and keyboard behavior |

Run the core release path with `npm run test:e2e:critical`. Tags can also be
used to filter reports and debug a scenario after its preconditions exist. A
single-tag command is not yet guaranteed to be independently runnable while
the suite remains one serial workflow.

## Scenario inventory

### 1. First-run setup (`@critical @setup`)

1. **Company setup succeeds.** A new administrator chooses Company careers
   site, enters identity, defaults, privacy, and notification values, creates a
   Careers page, and lands in Jobs with setup marked complete.
2. **Setup can be resumed.** The administrator saves during step 2, returns to
   Setup, sees the saved values, and completes the remaining steps.
3. **Job-board setup creates its workspace.** The administrator chooses
   Multi-employer job board and receives published Employer Registration,
   Submit a Job, and My Jobs pages.
4. **Restart is deliberate.** A completed administrator restarts setup after a
   confirmation, while existing jobs and applications remain intact.

Automated today: company setup, save/resume, company Careers page, and the
job-board page creation exercised by the employer workflow.

### 2. Administrator job authoring (`@jobs @admin`)

1. **Create and publish a schema-ready job.** An administrator enters title,
   description, employment type, physical or remote location, salary,
   organization, and deadline; the editor reports ready and publishes it.
2. **Incomplete structured data is actionable.** A missing required location
   or organization value produces an editor notice tied to that field.
3. **Saved facts remain consistent.** After reload, the editor values, public
   facts, application availability, and `JobPosting` JSON-LD agree.
4. **Close and reopen a job.** Closing removes the active application path and
   active schema; reopening restores both when dates remain valid.

Automated today: structured-field readiness, save/reload, public facts, and
schema agreement.

### 3. Public job discovery (`@discovery @public`)

1. **Browse open jobs.** A visitor opens Careers and sees only available jobs,
   result count, job facts, and working detail links.
2. **Search and combine filters.** Search, department, location, employment
   type, and workplace filters update results and expose removable filter chips.
3. **No results can recover.** An empty result explains the state and Clear
   filters restores the directory.
4. **Discovery is shareable.** Filter state survives the URL, pagination, and
   the filtered RSS link.
5. **Narrow layouts do not overflow.** Careers, department landing, job detail,
   and application form remain usable at 360 pixels.

Automated today: browse, search, multi-select filtering, recovery, RSS, pattern
composition, department context, and narrow-width overflow.

### 4. Candidate application (`@critical @candidate @applications`)

1. **Candidate submits successfully.** A visitor supplies required fields,
   accepts the privacy notice, uploads a valid PDF, submits once, and receives a
   neutral confirmation.
2. **Validation preserves safe work.** A recoverable validation error restores
   text fields once, focuses the error, and requires the local file to be
   selected again.
3. **Upload failure can be retried.** A simulated connection failure keeps the
   selected file, restores the submit button, and announces the error.
4. **Duplicate submission is private.** The same email and job, including case
   variants, receives the same confirmation without creating a second record or
   revealing the first application.
5. **Unavailable jobs reject applications.** Draft, closed, expired, and
   past-deadline jobs expose no active internal form and reject a forged POST.
6. **Employer recipient is disclosed.** On an internal job-board listing, the
   form identifies the company receiving candidate data.

Automated today: success, required/optional/omitted fields, progress and busy
states, retry, validation restoration, duplicate privacy, and recipient copy.

### 5. Recruiter application lifecycle (`@critical @recruiter @applications`)

1. **Review a new candidate.** A recruiter finds a candidate, opens inline
   review, reads the application, and downloads the protected resume.
2. **Advance the lifecycle.** The recruiter moves the application through New,
   Reviewing, Interview, Offered, Hired, and Rejected; each saved stage is
   visible after reload and in privacy-safe history.
3. **Add a private note.** A recruiter saves a note, sees it in notes/history,
   and candidate-facing pages never expose it.
4. **Find and filter candidates.** Candidate search, job filter, stage filter,
   pagination, and Clear filters retain predictable URL and result state.
5. **Bulk update selected candidates.** Selection count, destination stage,
   confirmation, keyboard submission, and retained filters all behave as shown.
6. **Reject requires intent.** The reject dialog names the candidate, traps and
   returns focus, and changes stage only after confirmation.

Automated today: review, stage changes, notes, protected resume, filters,
bulk-stage workflow, history, and narrow keyboard flow. Full one-by-one stage
progression is a follow-up scenario.

### 6. Export and candidate privacy (`@recruiter @applications`)

1. **Export respects the active scope.** A capable recruiter exports the
   filtered applications and receives only authorized records and columns.
2. **CSV content is safe.** Spreadsheet-formula prefixes are neutralized and
   multiline candidate content remains a valid row.
3. **Erase a candidate.** An authorized erasure removes candidate fields,
   notes, and resume while retaining only the allowed anonymous audit facts.
4. **Unauthorized access is neutral.** A user without ownership or capability
   cannot view, export, erase, or download another employer's candidate data.

Automated today: authorized export, formula neutralization, and protected
resume download. Erasure and cross-employer browser journeys are follow-ups;
their permission contracts already run in the PHP suite.

### 7. Employer registration and approval (`@employer @job-board`)

1. **Employer requests an account.** A visitor enters contact, company, email,
   strong password, and policy acceptance and receives a neutral verification
   response.
2. **Invalid registration is recoverable.** Missing policy acceptance, weak
   password, throttling, or failed anti-spam verification returns an accessible
   error without leaking whether an account exists.
3. **Operator approves an employer.** An administrator sees the pending queue,
   approves the request, and the employer can access Submit a Job and My Jobs.
4. **Rejected or pending employers are contained.** They can sign in but cannot
   create or manage listings until approved.

Automated today: public field/policy contract and an approved employer's portal
access. Real registration submission, verification, approval, and rejection are
the next critical-flow additions.

### 8. Employer job submission and moderation (`@critical @employer @job-board`)

1. **Save a minimal draft.** An approved employer saves a title-only listing,
   sees Draft in My Jobs, and resumes editing it.
2. **Validation preserves the draft.** Previewing an incomplete listing returns
   an actionable error while retaining sanitized edits.
3. **Preview a complete listing.** The employer supplies company, location,
   compensation, deadline, and routing data and sees candidate-facing facts in
   preview.
4. **Submit for review.** The employer submits from preview and sees Awaiting
   review; repeated submission does not duplicate the listing.
5. **Operator moderates explicitly.** The administrator approves, requests
   changes, or rejects from the listing queue, and both dashboards show the same
   outcome.
6. **Approval publishes the listing.** After approval, the employer sees
   Published and the public job is available with matching facts and schema.

Automated today: draft, persistence after validation, adaptive location and
routing fields, preview, submit, operator approval, published state, and public
recipient disclosure. Request-changes and reject UI paths are follow-ups.

### 9. Employer listing lifecycle (`@employer @job-board`)

1. **Manage owned listings.** Search, status filter, counts, and pagination show
   only the signed-in employer's listings.
2. **Duplicate into a clean draft.** Duplicate copies reusable content but
   clears deadline, expiration, featured state, and submission references.
3. **Close deliberately.** Confirmation closes a published listing, removes its
   application path, and records the action.
4. **Renew near expiration.** An eligible listing receives one reminder and can
   extend the saved listing expiration without changing application deadline.
5. **Relist an expired job.** Relist creates or prepares a draft that must pass
   preview and moderation again.
6. **Delete deliberately.** Confirmation moves an owned listing to trash and
   removes it from My Jobs without affecting other employers.

Automated today: ownership-scoped search/filter, duplication, deliberate
deletion, and expiration labels. Close, renew, expiration, and relist browser
paths are follow-ups and have PHP contract coverage.

### 10. Notifications and recovery (`@settings @notifications`)

1. **Configure notification identity.** An administrator saves sender identity,
   employer/candidate subjects and bodies, and sees placeholder-rendered
   previews.
2. **Send a transport test.** The action sends no candidate content and reports
   success or a sanitized operational error.
3. **Application mail failure is non-blocking.** Candidate submission still
   succeeds once, the recruiter sees a safe failure state, and Retry records one
   new attempt.
4. **Employer lifecycle notices are singular.** Registration, moderation, and
   expiration events send at most once per saved event.

Automated today: settings and rendered previews. Failure/retry and singular
delivery are currently covered by PHP contracts and need one connected browser
scenario.

### 11. Anti-spam perimeter (`@candidate @employer`)

1. **Default needs no challenge.** Candidate application and employer
   registration work with the provider disabled.
2. **Configured provider appears selectively.** Complete Turnstile or reCAPTCHA
   keys render only on enabled forms.
3. **Failed verification blocks persistence.** An invalid token returns an
   accessible neutral error and creates neither an application nor an employer.
4. **Incomplete configuration fails safe.** Missing keys render no broken
   widget and surface an administrator configuration warning.

Automated today: default unchallenged forms. Provider rendering and failure are
PHP-covered follow-up browser scenarios.

### 12. Authorization boundaries (`@applications @employer @job-board`)

1. **Employer A cannot see Employer B.** Changing job or application IDs never
   reveals B's jobs, candidates, notes, resumes, exports, or history.
2. **Recruiter permissions are granular.** View-only, manage, export, resume,
   and erase permissions independently allow or deny the matching UI action.
3. **Logged-out private links fail safely.** Resume, export, preview, and admin
   action URLs redirect or return a neutral denial without sensitive content.
4. **Nonces are enforced.** Replayed, missing, or cross-record action tokens do
   not mutate state.

These boundaries have broad PHP coverage. Add one browser-level cross-employer
journey and one granular recruiter journey; keep the exhaustive matrix out of
Playwright for speed.

## Recommended implementation order

1. Split the existing candidate, recruiter, and employer workflows into
   independent specs backed by named fixture factories.
2. Add the real employer registration-to-approval path, then remove the
   pre-approved employer shortcut from that one scenario.
3. Add full application stage progression and candidate erasure.
4. Add listing close, renew, expire, and relist.
5. Add one cross-employer authorization journey and one notification retry.
6. Enable two Playwright workers after every mutating scenario owns isolated
   records and the shared settings scenarios remain in a serial group.
