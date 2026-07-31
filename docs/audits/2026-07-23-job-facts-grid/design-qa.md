# Archived design QA — selected job-facts option 2

## Visual truth and tested state

- Source visual truth: `/Users/mikeyarce/.codex/generated_images/019f7231-927b-71d3-92a0-dae9c03a45f9/call_78QI1laCjPsgcnKG799N9YID.png`
- Desktop implementation: `docs/audits/2026-07-23-job-facts-grid/option-2-implementation-desktop-final.png`
- Mobile implementation: `docs/audits/2026-07-23-job-facts-grid/option-2-implementation-mobile.png`
- Focused desktop crop: `docs/audits/2026-07-23-job-facts-grid/option-2-facts-desktop-final.png`
- Focused mobile crop: `docs/audits/2026-07-23-job-facts-grid/option-2-facts-mobile-final.png`
- Combined comparison evidence: `docs/audits/2026-07-23-job-facts-grid/option-2-design-qa-comparison.png`
- URL: `http://localhost:8896/jobs/llamahire-fixture-demo-1/`
- State: public Senior Product Designer fixture with the invalid-phone application notice still visible; Twenty Twenty-Five active.
- Source pixels: 1448 × 1086, containing desktop and 375px mobile component references at DPR 1.
- Desktop CSS viewport: requested 1200 × 900; browser content capture 1185 × 889 at DPR 1. The facts component measured 645 × 397 CSS pixels and was compared through a same-pixel crop.
- Mobile CSS viewport: requested 375 × 900; browser content capture 360 × 864 at DPR 1 after scrollbar allocation. The facts component measured 320 pixels wide with no page-level horizontal overflow.

## Findings

- No actionable P0, P1, or P2 differences remain.
- Fonts and typography: passed. The implementation inherits the active FSE theme font, preserves the mock's muted labels and heavier values, avoids mid-word breaks, and maintains readable line heights at both tested widths.
- Spacing and layout rhythm: passed. Company and location receive full-width rows in constrained containers; workplace and employment share a balanced row; salary receives full width; the date footer remains a quiet two-column region. Padding and separators closely follow the source.
- Colors and visual tokens: passed. The implementation maps the source's white surface, near-black content, gray labels, subtle rules, and tinted date footer onto the existing LlamaHire/FSE color tokens.
- Image quality and asset fidelity: passed. The source contains interface icons only. The implementation uses the plugin's existing WordPress Dashicons rather than CSS drawings, text glyphs, or handcrafted SVG substitutes.
- Copy and content: passed. “Company,” “Employment type,” the fixture values, and the posted/deadline labels match the selected visual hierarchy.
- Responsiveness: passed. The component responds to its own container width as well as the viewport, so it remains readable inside the narrower desktop content column and at a 375px mobile viewport.
- Accessibility: passed. The semantic `dl`, `dt`, and `dd` structure remains intact; decorative icons stay hidden from assistive technology; contrast and reading order are preserved.
- Runtime: passed. The page loaded after each revision, the component had no horizontal overflow, and the reviewed browser console contained no warnings or errors.

## Comparison history

1. Initial implementation retained four primary columns inside the live page's 645px content column. Location, workplace, employment, and salary became cramped. Severity: P1.
   - Fix: introduced a container-aware responsive arrangement instead of relying only on viewport breakpoints.
2. The first container-query pass attempted to change the query container's own grid, which left one-track facts compressed. Severity: P0.
   - Fix: retained the twelve-track container and changed descendant spans to six or twelve tracks when the component is narrow.
   - Post-fix evidence: `option-2-implementation-desktop-final.png` shows readable company, location, paired workplace/employment, salary, and dates inside the live desktop column.
3. The first mobile pass kept date icons in two narrow columns, forcing both dates onto two lines. Severity: P2.
   - Fix: matched the selected mobile mock by removing decorative date icons at the small viewport, centering the label/value pairs, and keeping each date on one line.
   - Post-fix evidence: `option-2-implementation-mobile.png` shows both dates on one line with no horizontal overflow.
4. Final combined comparison: `option-2-design-qa-comparison.png`. No actionable P0/P1/P2 mismatch remains.

## Primary checks

- Public job-detail route loaded successfully after each CSS/PHP revision.
- Desktop component rendering checked at a 1200 × 900 requested viewport.
- Mobile component rendering checked at a 375 × 900 requested viewport.
- Component and page horizontal overflow checked at mobile.
- Existing application form remained present and visually unaffected; no form submission was needed for this component-only change.
- Browser console warnings and errors checked: none.
- PHP syntax check: passed.
- Git whitespace/error check for changed files: passed.
- WordPress smoke suite could not run because the separate `wp-env` CLI service is stopped; browser verification used the already-running demo site.

## Follow-up polish

- P3: At especially narrow embedded widths, “Employment type” may wrap onto two label lines. The value remains clear and the selected layout hierarchy is preserved.

final result: passed
