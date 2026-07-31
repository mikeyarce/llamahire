# Milestone 3A review — site purpose and lightweight job board

Date: 2026-07-22

## Outcome

Milestone 3A meets its planned Free-product acceptance criteria. One WordPress job model supports company careers sites and community job boards without a company/tenant entity. Public self-registration remains intentionally outside this milestone; administrators approve accounts and assign the Employer role.

## Acceptance evidence

- Site purpose is explicit, reversible, sanitized, and defaults existing installations to company mode without rewriting jobs or applications.
- Job-board mode creates/selects Submit a Job and My Jobs pages and treats site identity as the board operator rather than silently as every listing’s employer.
- Employers submit pending jobs from the frontend, reuse their latest company/application defaults, upload logos into the Media Library, and can preview, edit, resubmit, close, or move only their own listings to trash.
- Board administrators moderate every listing. Submission/resubmission notifies the board; publication, changes requested, and decline notify the owning employer.
- Jobs select internal, URL, or email applications. Internal forms name the employer that receives the candidate’s data.
- Central ownership checks constrain applications, private notes, resumes, exports, retries, and audit events. Two-employer tests verify cross-company denial while administrators retain board-wide access.
- The audit table cannot store candidate names, emails, phone numbers, cover letters, notes, resume paths/names, IP addresses, or user agents. Erasure history retains only unlinkable numeric references, event/state values, actor ID, and UTC time.
- The portal and candidate paths use semantic forms, server-side validation, nonces, accessible status/error feedback, no-JavaScript submission, bounded queries, and a narrow-screen table fallback.

## Validation

- 162 WP-CLI smoke checks cover schema migration 8, forbidden audit columns, event creation, post-erasure history, employer scoping, portal prefilling, and management controls.
- Seven Playwright journeys cover setup, editor, public application, recruiter review, visible application/activity history, employer logo submission, moderation, preview, recipient disclosure, prefilling, and confirmed trash behavior.
- PHP/JavaScript syntax checks, `git diff --check`, and release-package inspection passed; the ZIP includes the audit implementation and excludes development-only tests and fixture tools.

## Deferred by explicit product boundary

- Public employer self-registration requires email verification, abuse controls, recovery, consent copy, and an operator policy; administrator-approved accounts are the Free MVP policy.
- Paid listings, packages, billing, teams, shared company profiles, and cross-user company membership remain Pro concerns.
- Google Rich Results/URL Inspection and the broader assistive-technology/theme matrix remain item 23’s release-candidate validation work.
