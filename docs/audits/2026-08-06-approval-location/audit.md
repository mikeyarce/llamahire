# Approval and employer location audit

Date: August 6, 2026

## Scope

- Administrator entry points for employer-account and job-listing approvals.
- Location fields on the front-end employer job form.

## Evidence

1. `01-location-fields.png` — the employer form presents Location, Display location, City or locality, Region, Country, and remote eligibility together.
2. `02-admin-dashboard.png` — the dashboard surfaces listing reviews, but does not surface employer-account approvals as a separate task.

## Findings

### Approval

- LlamaHire has two different approvals: employer accounts and submitted job listings. The interface does not make that distinction explicit.
- Employer accounts are approved from the WordPress Users table. The approval action is a row action, which WordPress normally reveals only when the username row is hovered or focused.
- Submitted jobs are opened from the dashboard's Review listings link, then approved through WordPress's generic publication controls. There is no primary action labeled Approve job.
- The audit could not capture a live pending listing or pending employer because the current fixture state contains none. The current activity log confirms a listing was recently submitted and approved.

### Location

- The first select is labeled Location even though its values describe location type: On-site, Hybrid, or Remote.
- Display location and City or locality appear at equal prominence without explaining which value is authoritative.
- For a structured physical address, public job output currently derives its label from city, region, and country, so Display location is usually redundant and may not produce the public shorthand promised by its help text.
- Physical-address and remote-eligibility inputs appear simultaneously instead of adapting to the selected location type.

## Recommended direction

1. Add separate dashboard attention items for Employer accounts awaiting approval and Job listings awaiting review.
2. Provide visible task actions: Approve employer on the account row, and Approve and publish, Request changes, and Decline on a pending listing review screen.
3. Rename the employer form select to Location type.
4. Remove Display location from the main employer form and derive the public label from City, State/province/region, and Country. Preserve existing stored values as a compatibility fallback.
5. Show the physical address fields only for On-site and Hybrid jobs. Show Eligible applicant countries only for Remote jobs.

## Evidence limits

Screenshots establish hierarchy and discoverability problems, but do not establish full keyboard or screen-reader compliance. A pending approval fixture is needed to verify the proposed action states end to end.
