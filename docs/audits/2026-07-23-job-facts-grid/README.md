# Job facts grid line review

## Step 1 — Job detail facts

Health after fix: good.

The reported seven-item grid placed four fixed-width facts on the first row and three fixed-width facts on the second. Because the empty fourth slot had no element, the row divider stopped at 75% of the component.

The compact desktop grid now uses a 12-column track:

- Four-item rows span three tracks per fact.
- Three-item final rows span four tracks per fact.
- Two- and one-item final rows also distribute evenly across the full width.
- Responsive layouts reset to two columns below 980px and one column below 360px.

This keeps every horizontal divider continuous and gives incomplete final rows balanced widths.

## Evidence

- Reported state: `00-reported-grid.png`
- Corrected desktop page: `01-fixed-desktop.png`
- Corrected mobile page: `02-fixed-mobile.png`
- Corrected component: `03-fixed-facts-component.png`
- Before/after comparison: `04-before-after.png`

## Accessibility and limits

The fix changes only visual grid placement. The semantic `dl`, `dt`, and `dd` structure and visually hidden compact labels remain unchanged. Screenshot review confirms layout and overflow behavior; automated smoke checks cover the generated classes and markup.
