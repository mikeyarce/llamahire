# Input, settings, and menu review — 2026-07-17

## Scope

This review covers the current setup flow, settings page, job editor panels, block inspector controls, public directory/application forms, recruiter application views, and the Jobs admin menu. It records the control decision for every current value family and establishes the rule for future fields.

## Control decisions

| Value or task | Intended control | Review outcome |
| --- | --- | --- |
| Organization and per-job logos | WordPress Media Library with preview, replace, and remove actions | Fixed in setup, settings, and the job editor. The stored URL remains compatible with schema output, but users no longer type it. |
| Featured job image | Core post Featured Image control | Already correct. |
| Future color values | WordPress color picker in classic admin; `ColorPalette`/`ColorPicker` in the block editor | No color values exist yet. Raw text color fields are not allowed when one is added. |
| Organization website and per-job website override | URL input | Already correct. |
| Hiring inbox and candidate email | Email input | Already correct. |
| Candidate phone | Telephone input with telephone autocomplete | Already correct. |
| Job deadline | Date input plus server-side real-date validation | Already correct. |
| Salary bounds | Non-negative number inputs plus ordered-range validation | Already correct. Decimal step support remains a small authoring-polish follow-up. |
| Employment type, workplace, pay period, application status | Select controls with constrained values | Already correct. |
| Featured/closed/filter flags | Checkbox or toggle controls | Already correct. Publication-versus-application-state wording remains in the job-authoring polish slice. |
| Privacy and Careers pages | Published-page selectors | Already correct; users do not enter page IDs. |
| Application-form job target | Published-job selector, with “Current job” when embedded in a job | Fixed; the block no longer asks authors for a job ID. |
| Resume | File input constrained to supported document types, with server-side content validation | Already correct. Resume files intentionally do not enter the Media Library because candidate documents are private. |
| Country/currency codes | Short uppercase code field with clear format help and strict server validation | Accepted for the current alpha because WordPress has no core country/currency selector and LlamaHire must not ship a stale partial list. Reassess a maintained searchable selector before 1.0 localization sign-off. |
| Remote eligible countries | Comma-separated validated country codes | Functional but not ideal. Replace with an accessible tokenized multi-country selector when the maintained country dataset is introduced. |
| Search, notes, cover letter, organization/location text | Search, textarea, or text controls matching the content | Already correct. |

## Settings information architecture

The settings and setup surfaces now use the same progression:

1. Organization identity
2. Job defaults
3. Notifications
4. Candidate privacy
5. Careers page

This order follows setup-to-operation dependencies: identify the employer, establish reusable job values, choose where operational messages go, disclose candidate-data use, then connect the public directory.

## Jobs menu order

The submenu now uses a stable task order while retaining the standard WordPress content entries first:

1. Jobs
2. Add New
3. Dashboard
4. Applications
5. Departments
6. Setup
7. Settings

Entries a user cannot access remain absent through normal WordPress capability checks. Unknown extension-provided entries are retained after the known LlamaHire entries.

## Follow-ups

- Complete the separate Milestone 1 job-authoring slice for Published versus Closed wording, duplicate/preview feedback, and next-action empty states.
- Revisit country, currency, and multi-country controls during localization work using one maintained source of truth rather than embedded partial lists.
- Include control selection in feature review: images use Media Library, colors use WordPress color controls, related posts/pages use selectors, and IDs stay implementation details.
