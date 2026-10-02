# User-flow E2E review — October 2, 2026

This review covers 37 user-flow tests: the original 11-test serial hiring
workflow, two independent regression tests, and 24 new independently runnable
user journeys in `tests/e2e/user-flows.spec.js`. The separately configured,
opt-in theme matrix adds six more cases to the repository inventory.

## Coverage added

- Employer registration, policy/password validation and retry, email verification,
  pending-account restrictions, manual approval, automatic approval, and single-use
  verification links.
- Admin job creation and publishing with public facts matching JobPosting schema.
- Request-changes and decline moderation outcomes; closing/reopening; renewal
  preserving the application deadline; expired listing relisting through draft,
  preview and moderation.
- Draft, closed, listing-expired and deadline-expired job submission rejection
  using valid anonymous nonces; external website and email application routing.
- Every candidate stage, rejection cancellation and focus return, candidate
  erasure and revocation of a previously working protected resume link.
- Cross-employer job/candidate isolation, protected resume denial, ownership-scoped
  CSV export, and denied erasure using valid authenticated session nonces.
- Non-blocking application mail failure, successful explicit retry, attempt counts,
  and duplicate submission without another notification attempt.
- Saved application requirements and privacy text affecting the public form and
  server validation; both anti-spam providers, incomplete-key fallback, per-form
  activation, and failed provider verification preventing persistence.
- Real resume submission and on-site employer draft/preview/submission with
  JavaScript disabled.

## Test review

The new tests own fresh fixture records and restore the prior settings after each
scenario. The helper refuses any site other than the disposable wp-env URL, and
normal E2E cleanup also recovers fixtures after an interrupted run. Browser-created
admin jobs are tracked for cleanup, including interrupted draft authoring.

Assertions check saved state or public outcomes in addition to confirmation text.
Permission checks reproduce the attacking browser's session and confirm the
actual denial reason. An employer's export capability is retained: a request
filtered to another employer's job returns the CSV header without candidate rows.

Mail and provider verification are intercepted only while the fixture registry is
active. No candidate mail body is recorded and no real email or anti-spam service
is contacted. These checks prove transport acceptance/recovery and verification
plumbing; they do not prove inbox delivery or a live provider widget's behavior.
The shared-IP registration limiter is disabled for these journeys; PHP tests retain
rate-limit coverage. One worker remains necessary because settings are site-wide.

The complete-suite run found a stale setup assertion: a Careers status paragraph
now also contains its View page link. The repaired assertion verifies both the
status and that link's destination. Native recruiter disclosures also use
keyboard activation after pointer stability checks timed out in the older tests.

## Remaining finding

**P2 — Workplace switching needs a save/reload without JavaScript.** The employer
form initially hides one location group in PHP (`includes/class-employer-portal.php`,
lines 565–573). Selecting Remote from an on-site draft does not reveal the required
eligible-country input when JavaScript is disabled; the reverse switch has the
same problem. The completed no-JavaScript journey covers on-site posting. A future
fix should expose both groups in the baseline form and apply conditional visibility
and required attributes through progressive enhancement, preserving server-side
validation. Add a no-JavaScript workplace-switching regression with that fix.

Employer-account rejection has no supported action or status today; the tests
cover pending restrictions and job-listing rejection rather than inventing an
account-rejection flow.

## Validation

All 24 new journeys passed together in two complete-suite attempts. JavaScript
syntax checks, PHPCS for both new PHP helpers, PHPCompatibilityWP targeting
PHP 7.4+ for both helpers and the cleanup change, and `git diff --check` passed.
The existing cleanup file retains its baseline PHPCS findings; the cleanup hook
adds none.

The final combined run passed the first eight existing hiring tests, then the
applications workspace redirected into first-run setup. Concurrent browser runs
from another chat used and reset this same disposable site during validation.
The complete 37-test user-flow suite therefore does not have a verified green
run from this review. Run setup and the suite with exclusive use of wp-env to
establish that result; keep the opt-in theme matrix separate from that run.
