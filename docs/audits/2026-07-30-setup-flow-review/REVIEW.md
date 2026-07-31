# LlamaHire Setup flow review

Date: July 30, 2026

Scope: the four-step Setup flow at `http://localhost:8896/`, including the company and community job-board paths, field behavior, validation contracts, narrow reflow, and final review.

No product code or saved Setup values were changed. The flow was inspected without submitting either Setup form.

## Overall verdict

The flow has a strong foundation: it is genuinely step-based, keeps each screen focused, adapts its copy for company and job-board modes, uses familiar WordPress controls, and provides a useful final summary. The highest-value improvements are about preventing incomplete or surprising setup rather than visual redesign.

## Step health

1. **Site purpose — Healthy with a context gap.** The two large radio choices are easy to compare and their full cards are clickable. The job-board option needs a clearer preview of the additional setup it entails.
2. **Organization identity — Generally healthy, with hidden/default-field friction.** Name, website, and logo are clear. Required status is not visible, country and currency expect technical codes, and the required currency sits inside a collapsed disclosure.
3. **Candidate privacy — Understandable, but incomplete as a privacy checkpoint.** The inbox, disclosure, and policy page are logically related to applications. Retention is not reviewed, policy inheritance is opaque, and required fields are not visibly marked.
4. **Careers page and review — Needs stronger safeguards.** The create/select choice is simple, but any published page can be selected even when it has no jobs block. Creating a page publishes it immediately without saying so. The summary is too sparse to catch several consequential choices.

## What works well

- The first decision changes labels and guidance without forcing the user to understand internal settings.
- Back and Continue preserve values within the current browser session.
- The company website disappears in job-board mode instead of asking for a duplicate URL.
- Logo actions are visually balanced and the selected asset is easy to inspect.
- Job defaults are progressively disclosed, keeping the main identity screen focused.
- Inactive Careers-page controls are disabled and the active control becomes required.
- Step headings receive focus after navigation, and field labels/help associations are generally strong.
- The four-step tracker and form reflow into a single-column narrow layout without horizontal page overflow.

## Highest-impact improvements

### 1. Preserve or explicitly discard skipped progress

`Skip for now` submits a separate form containing no Setup values. Anything entered in the wizard is lost. Either save a draft and resume at the last completed step, or rename/confirm the action so the loss is explicit.

### 2. Validate the selected public jobs page

Step 4 accepts any published page. A user can complete Setup with a Sample Page that contains no LlamaHire jobs directory, leaving candidates with no usable job listing. Restrict the chooser to compatible pages, detect the jobs block, or offer to add the required block.

### 3. Finish the job-board path

Choosing `Community job board` changes copy, but Setup still configures only one Careers page. A usable multi-employer board also needs the Submit a Job and My Jobs pages plus employer-access guidance. Add those to the job-board path or end with a clearly incomplete checklist instead of presenting Setup as fully complete.

### 4. Make consequences visible before completion

Creating a new Careers page publishes it immediately. State that clearly near the option. The phrase “using the LlamaHire pattern” is implementation language; explain that the new published page will contain the job search/listing experience and can be edited afterward.

### 5. Improve required-field clarity

Organization/job-board name, currency, inbox, privacy text, and the active page control are required, but sighted users receive no required marker or “Required fields” note. Native validation exists, but it is reactive rather than anticipatory.

## Field-by-field notes

### Step 1 — Site purpose

- **Company careers site:** Clear default and plain-language description.
- **Community job board:** Rename to “Multi-employer job board” if “community” is not a product requirement. Preview what changes: per-job employers, employer accounts, submission pages, and routing.
- **Dynamic behavior:** The next-step heading changes to “Job board details,” but the tracker still says “Organization identity.” Use “Identity & defaults” or dynamically switch to “Board identity.”

### Step 2 — Organization identity

- **Organization/job-board name (required):** Good default from the site title. Add visible required treatment.
- **Organization website (optional, company only):** Good to hide it for job boards. The help could say why the current WordPress URL is already sufficient in the common case.
- **Logo (optional):** Clear control, but the 220px preview dominates the screen. Replace the ratio-heavy instruction with a recommendation such as “Square or landscape, at least X px wide; SVG/PNG preferred” if supported.
- **Default city/locality and region (optional):** Useful, but explain that editors can override these on every job.
- **Default country (optional):** A two-letter ISO code is technical and error-prone. Use a country selector while storing the same code.
- **Default currency (required):** A three-letter code is also technical. Use a currency selector, show the current symbol/name, and do not hide a required field inside an unmarked collapsed section.
- **Job defaults disclosure:** Show a compact summary when collapsed, for example `Vancouver, BC, CA · CAD`, so users can verify inherited defaults without reopening it.

### Step 3 — Candidate privacy

- **Hiring/board notification inbox (required):** Clear purpose. Consider a test-email action after completion or a visible verification reminder.
- **Candidate privacy text (required):** The mode-sensitive default is helpful. Add a candidate-facing preview and make clear that this is operational guidance, not legal advice.
- **Privacy policy page (optional):** “Use the WordPress privacy policy” does not reveal which page that currently means or whether one is configured. Show the resolved page title, or warn when WordPress has no policy page.
- **Missing retention review:** Candidate retention is a privacy-relevant default but remains hidden in Settings at 365 days. Add it here under an advanced disclosure or include it in the final summary.
- **Step name:** Since the screen also configures application notifications, “Applications & privacy” more accurately sets expectations.

### Step 4 — Careers page and summary

- **Create a new page:** State that completion immediately publishes the page. Offer a preview or concise description of what the pattern includes.
- **New page title:** When a user changes the default company mode to job-board mode, the client updates most copy but leaves the default title as `Careers`; the server-side initial job-board default is `Jobs`. Update an untouched default title when the mode changes.
- **Use an existing page:** Validate that it contains a LlamaHire jobs directory before completion.
- **Terminology:** “Public jobs page” works for both company and job-board modes better than “Careers page.”
- **Setup summary:** Add location/currency defaults, privacy-policy resolution, retention, and page-publication behavior. Include Edit links back to each step. For job boards, include employer pages/access readiness.

## Accessibility risks and verification gaps

- Required semantics are present, but visible required indicators are absent.
- The 11px tracker labels are cramped in the narrow layout and may become difficult at zoom.
- Step headings are programmatically focused while their focus outline is removed. A visible focus treatment would make the navigation change easier to track for keyboard and low-vision users.
- The native validation path was inspected structurally but no screen-reader speech log was captured.
- Screenshots cannot verify actual contrast ratios, full keyboard order, high-contrast mode, browser-native validation speech, or 200–400% zoom behavior.

## Recommended sequence

1. Add selected-page compatibility validation and clarify immediate publication.
2. Decide whether Skip saves a draft or explicitly discards progress.
3. Complete the job-board-specific page/access setup.
4. Fix required-field visibility and country/currency controls.
5. Expand the final summary and make it editable.
6. Polish terminology, collapsed summaries, and narrow focus/tracker behavior.

## Evidence

- `01-site-purpose.png` — company path, Step 1
- `01b-job-board-purpose.png` — job-board selection
- `02-organization-identity.png` — company identity
- `02b-job-defaults.png` — expanded job defaults
- `02c-job-board-identity.png` — job-board identity
- `03-candidate-privacy-viewport.png` — company privacy
- `03b-job-board-privacy.png` — job-board inbox guidance
- `04-careers-page-top.png` — final page choice and review
- `05-careers-page-mobile-adjusted.png` — narrow-layout reflow
