# Hiring page design review

Date: 2026-09-25

## Audit scope

Reviewed the WordPress admin **Hiring** workflow against `DESIGN.md` at a
1440×1000 desktop viewport and a 390×844 narrow viewport. The screenshots use a
no-match search state so candidate data is not exposed.

User goal: scan the hiring pipeline, filter candidates, and move a candidate to
the next stage with clear, predictable feedback.

Accessibility target: WCAG 2.2 AA-oriented interaction and responsive behavior.
This was a visual and implementation review, not a full conformance audit.

## Overall verdict

The desktop board is compact, legible, and recognizably WordPress-native, but it
does not yet conform to the new design contract. The most important gaps are
narrow-width reflow, a mobile filter sizing bug, hidden filter labels, and local
status colors that diverge from the canonical tokens.

## Steps

1. **Desktop pipeline — generally healthy with token drift.** The page follows
   the workflow structure: one header, filters, and one board. Stage grouping is
   clear, empty states are understandable, and the flat treatment fits the
   contract. The 24px page title and stage badge colors/shapes do not match the
   canonical tokens.
2. **Narrow pipeline — unhealthy.** The filter form expands to 312px high because
   the job-filter label keeps a 260px flex basis after the layout switches to a
   column. The five 220px stage columns remain in one horizontal strip, so the
   next stage is clipped and the board begins far below the filters.

## Strengths

- The page uses a clear workflow hierarchy and a single H1.
- WordPress controls, Dashicons, neutral canvas colors, borders, and low-shadow
  surfaces make the page feel native to wp-admin.
- Each stage combines color with a text label and count rather than relying on
  color alone.
- The board has an accessible name, uses section headings, and status feedback
  is exposed through an `aria-live` region.
- Candidate details use the contract's drawer pattern, preserving board context.

## Findings

### P1 — Narrow filter sizing creates a large blank gap

At 390px, `.llamahire-hiring-filters` is 312px tall even though its two visible
controls total about 92px. The mobile layout changes the form to a column, but
`.llamahire-job-filter` retains `flex: 0 1 260px`. Reset the mobile flex basis to
`auto` and keep the form gap on the named 8/12/16px rhythm.

### P1 — The pipeline does not reflow at narrow widths

The mobile grid remains `220px 220px 220px 220px 220px` inside a 348px scroller.
This contradicts the contract's instruction to stack columns and actions at
narrow widths. Stack stage sections, or provide an explicit stage switcher with
one stage visible at a time. Do not rely on a partially visible second column to
communicate horizontal scrolling.

### P1 — Filter labels are visual-only placeholders

Both filter labels are screen-reader-only. Once search text is entered, the
search control no longer tells sighted users what it filters. The contract says
labels belong above controls. Add visible 14px labels for “Search candidates” and
“Job title”; keep the existing programmatic labels.

### P2 — Status badges do not use the canonical status tokens

The board defines a separate set of stage colors. Offer and Hired share the same
green, status text inherits the normal ink color, and the labels use 3px radii
instead of the canonical pill shape. Map New, Reviewing, Interviewing, Offer,
and Hired directly to the `DESIGN.md` background/text token pairs and pill
geometry so application state is consistent across screens.

### P2 — The page title is undersized

The Hiring-specific rule overrides the shared 28px title with 24px. Remove the
override so this page follows the one-page-title typography contract.

### P2 — Filtered empty state gives the wrong next action

With an active no-match search, every stage says “No candidates” and suggests
dragging a candidate into the stage. The useful next action is to clear or revise
the filters. Add one board-level no-results state with a visible “Clear filters”
action, while retaining stage-specific empty copy for an unfiltered board.

### P2 — Moving candidates is not sufficiently discoverable

The visible affordance is a 15×15 aria-hidden move glyph, while the drag help is
visually hidden. Dragging is pointer-oriented; keyboard users must discover the
candidate drawer and change the Stage select there. Keep that alternative, but
make the non-drag path and short drag guidance visible, especially on touch
layouts where drag-and-drop is less predictable.

### P3 — Fixed 690px stages waste space

The stage `min-height: 690px` produces large empty surfaces on short or filtered
boards and compounds the narrow-screen scroll cost. Use a content-aware minimum
on narrow widths and reserve any viewport-filling treatment for wide workflow
layouts.

## Evidence limits

- Keyboard traversal was sampled; the skip links showed the contract's 2px
  `#3858e9` focus indicator. Focus visibility for every card, drawer control,
  dialog, and drag alternative still needs a complete keyboard pass.
- Screen-reader announcements, live-region timing, drag behavior, zoom at 200%,
  and color contrast were not fully verified.
- Candidate detail screenshots were intentionally omitted to avoid exposing
  candidate data.

## Recommended order

1. Fix the mobile filter flex basis and narrow pipeline reflow.
2. Add visible filter labels and a filtered no-results action.
3. Replace local stage colors/shapes with canonical status tokens.
4. Restore the 28px page title and reduce empty-stage minimum height.
5. Make the non-drag stage-change path explicit and complete keyboard/zoom QA.
