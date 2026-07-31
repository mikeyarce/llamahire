# Recruiter operations design checkpoint

Date: July 26, 2026

Environment: WordPress Studio demo at `http://localhost:8896`, WordPress 7.0.2, Chromium, Northstar fixture data

## Implemented in this slice

- Job, application-status, and candidate name/email filters.
- Result-range and total-count feedback.
- Pagination and CSV export that preserve active filters.
- Job-list application counts that link directly to the filtered inbox.
- A responsive application-list treatment that becomes labelled record cards on narrow screens.

## Screens

1. [Applications inbox — desktop](01-applications-desktop.png)
2. [Filtered applications inbox — desktop](02-applications-filtered-desktop.png)
3. [Filtered applications inbox — 390 px](03-applications-mobile.png)
4. [Job list with application-count links](04-job-list-count-links.png)
5. [Revised mobile application cards — 390 px](05-applications-mobile-redesign.png)
6. [Mobile before-and-after comparison](mobile-comparison.png)

## Review notes

- The desktop view follows WordPress list-table and filter conventions instead of introducing a separate visual system.
- The filtered state keeps the chosen job, status, and candidate query visible, reports the exact result count, and changes the export action to “Export filtered CSV.”
- At 390 px the filter controls become full-width and each application becomes a labelled record card without horizontal overflow.
- During capture, WordPress core’s narrow-list-table rule overrode the generated field labels. The selector was corrected and the final narrow screenshot verifies the labels are visible.
- The second mobile review replaces the narrow label/value grid with candidate-first cards, full-width job information, paired status badges, and a quiet received-date footer. It also keeps all five status filters on one line at the tested width.
- The job list reuses the existing “Publication and hiring” column so the new count links do not add another wide column.

## Validation

- 174 WP-CLI smoke checks passed.
- All seven Playwright hiring-workflow tests passed.
- The final browser pass found no console warnings or errors on the reviewed recruiter screens.

## Next design checkpoint

Review bulk status actions and the candidate-detail information hierarchy before implementing the next Milestone 4 slice.
