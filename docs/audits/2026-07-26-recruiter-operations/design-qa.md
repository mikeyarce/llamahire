# Design QA — recruiter inbox mobile redesign

## Visual truth and tested state

- Source visual truth: `docs/audits/2026-07-26-recruiter-operations/03-applications-mobile.png`, plus the approved direction to use stacked candidate cards, compact status badges, quieter metadata, and a less crowded status row.
- Implementation screenshot: `docs/audits/2026-07-26-recruiter-operations/05-applications-mobile-redesign.png`.
- Combined comparison evidence: `docs/audits/2026-07-26-recruiter-operations/mobile-comparison.png` (previous implementation on the left, redesign on the right).
- URL: `http://localhost:8896/wp-admin/admin.php?page=llamahire-applications&status=new&job_id=1576&s=Avery`.
- State: filtered recruiter inbox for Senior Product Designer, New, and Avery.
- Viewport: requested 390 × 844 CSS pixels; captured page content is 375 × 812 pixels at DPR 2 after browser scrollbar allocation.
- Density normalization: source and implementation captures are both 375 × 812 pixels from the same browser, viewport, fixture data, and filter state, so no resampling was required.

## Findings

- No actionable P0, P1, or P2 differences remain against the approved redesign direction.
- Fonts and typography: passed. Candidate names now establish the card heading, email and received metadata are quieter, 13px status navigation remains readable, and job/status labels use compact uppercase supporting text without colliding with values.
- Spacing and layout rhythm: passed. Candidate and job information use full-width rows, workflow and email states share a balanced two-column row, and received time forms a quiet footer. The cards have consistent padding, separators, and vertical spacing.
- Colors and visual tokens: passed. The redesign stays within WordPress admin neutrals and link blue while adding restrained semantic badge colors for workflow and delivery states.
- Image quality and asset fidelity: passed. This interface contains no raster assets or non-standard icons; the existing WordPress admin toolbar and controls remain unchanged.
- Copy and content: passed. Existing candidate, job, status, email-status, received-time, filter, result-count, and export language is preserved.
- Responsiveness: passed. The page has no horizontal overflow at the tested width, all five status filters fit on one line, filters remain full-width, and application records form scannable cards.
- Accessibility: passed for this visual slice. The underlying table, headers, caption, links, and filter semantics remain in the DOM; visible card labels clarify the mobile reading order; badges do not rely on color alone.
- Runtime: passed. Filters and links remained present, the expected four records rendered, and the reviewed page reported no browser warnings or errors.

## Full-view comparison evidence

The combined comparison shows the redesign replacing the cramped label/value columns with a clear hierarchy: candidate first, job second, two compact status cells, and received metadata last. The filter state and amount of above-the-fold context remain comparable to the source.

## Focused region comparison evidence

The first application card is large enough in the full-view comparison to evaluate typography, wrapping, labels, badges, dividers, and date treatment; a separate crop was not necessary.

## Comparison history

1. The previous mobile layout used narrow label/value columns. Candidate email, job title, and dates wrapped excessively and all fields had equal visual weight. Severity: P1.
   - Fix: changed each record to a two-column card grid with full-width candidate and job rows, paired status cells, badges, and a quiet date footer.
2. The first redesign render inherited WordPress core’s floated `td::before` labels, causing labels to overlap job and status values. Severity: P0.
   - Fix: explicitly reset pseudo-label float, position, width, and alignment at the narrow breakpoint.
   - Post-fix evidence: `05-applications-mobile-redesign.png` shows separated labels and values throughout the visible cards.
3. The initial tightened status row used 12px text. Severity: P2.
   - Fix: restored the WordPress-native 13px size and reduced inter-item spacing; all filters still fit without page overflow.
   - Post-fix evidence: `mobile-comparison.png` shows the final single-line status row and revised card hierarchy.

## Primary checks

- Filtered local recruiter route loaded successfully after each revision.
- Mobile rendering checked at a requested 390 × 844 viewport.
- Page horizontal overflow checked: none.
- All five application-status filters fit within their navigation container.
- Expected four filtered application records rendered.
- Browser console warnings and errors checked: none.
- PHP syntax check: passed.
- Git whitespace/error check: passed.
- WordPress smoke suite: 174 checks passed.

## Follow-up polish

- P3: If future translated status names are substantially longer, the status row can remain horizontally scrollable without creating page-level overflow.

final result: passed
