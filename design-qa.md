# Job Filter Chips and Automatic Filtering Design QA

**Source visual truth**

- Approved interactive concept: `/Users/mikeyarce/.codex/visualizations/2026/07/31/019fb625-4d1a-7061-9e43-fdb3e61853f7/job-filter-chips-concept.html`
- Browser-rendered source capture: `docs/audits/2026-07-31-job-filter-chips/01-approved-concept.jpg`
- Source capture: 1280 × 720 pixels/CSS pixels at `devicePixelRatio: 1`.

**Implementation evidence**

- Live Studio page: `http://localhost:8896/`
- Desktop: `docs/audits/2026-07-31-job-filter-chips/02-implementation-desktop.jpg`
- Equal-size focused crop: `docs/audits/2026-07-31-job-filter-chips/02-implementation-focus.jpg`
- Active-filter state: `docs/audits/2026-07-31-job-filter-chips/03-implementation-filtered.jpg`
- Mobile: `docs/audits/2026-07-31-job-filter-chips/04-implementation-mobile.jpg`
- Side-by-side: `docs/audits/2026-07-31-job-filter-chips/05-comparison.png`
- Revised disclosure controls: `docs/audits/2026-07-31-job-filter-chips/06-multi-select-default.jpg`
- Two-value menu state: `docs/audits/2026-07-31-job-filter-chips/07-multi-select-open.jpg`
- Revised mobile: `docs/audits/2026-07-31-job-filter-chips/08-multi-select-mobile.jpg`
- Revised side-by-side: `docs/audits/2026-07-31-job-filter-chips/09-multi-select-comparison.png`
- Pagination before/after: `docs/audits/2026-07-31-job-filter-chips/10-pagination-before.jpg` and `docs/audits/2026-07-31-job-filter-chips/11-pagination-after.jpg`
- Pagination mobile: `docs/audits/2026-07-31-job-filter-chips/12-pagination-mobile.jpg`
- Pagination comparison: `docs/audits/2026-07-31-job-filter-chips/13-pagination-comparison.png`
- Desktop browser viewport: 1440 × 1100 CSS pixels at `devicePixelRatio: 1`; browser capture: 1425 × 1089 pixels. The component-focused implementation crop and source capture are both 1280 × 720 pixels for the combined comparison.
- Mobile browser viewport: 390 × 844 CSS pixels at `devicePixelRatio: 1`; browser capture: 375 × 812 pixels. The difference is browser chrome/scrollbar allocation, not density scaling.
- State: Twenty Twenty-Five block theme, default query for the full-view comparison; Design department selected for the active-chip capture.

**Full-view comparison evidence**

- The implementation reproduces the selected concept's search-first hierarchy, one-row desktop chip controls, compact featured toggle, result count, clear action, and card transition into results.
- The source intentionally demonstrates a dark theme while the live page uses the active theme's light palette. The implementation inherits WordPress theme colors and typography instead of fixing the component to the concept's palette, which satisfies the requested theme customizability.
- Real fixture data produces four cards per row rather than the concept's illustrative two cards. This is data and container-width variation outside the filter component, not design drift.

**Focused comparison evidence**

- Search and filter controls have comparable height, spacing, pill radii, and visual order in the equal-size combined image.
- Active disclosure controls invert to the theme contrast color and separate removable active-filter chips appear below the result summary. Controls and chips use the WordPress Dashicons icon family rather than handcrafted marks.
- Each categorical menu opens to native checkboxes, keeps enough trailing space for its arrow, and truncates unusually long summary text before the arrow rather than underneath it.
- At 390 px, the search action stacks below the keyword field, Department and longer controls use full rows, Job type and Workplace share a row, and there is no document-level horizontal overflow.
- Fonts and typography inherit the active theme at readable native weights and line heights. Spacing uses low-specificity defaults and WordPress block supports. Colors map to theme presets and exposed LlamaHire custom properties. No raster imagery is present in either filter design, so image quality is not applicable. App-specific copy remains concise and accessible.

**Findings**

- No actionable P0, P1, or P2 issues remain.
- P3: the concept includes a small descriptive subtitle beside the heading. The production pattern leaves surrounding heading copy to the site editor, so the filter block does not manufacture this content.

**Interaction and quality checks**

- Selecting Design updated the URL to `?department=design-northstar-labs`, replaced only the result region, displayed `2 open roles`, and exposed a removable `Design — Northstar Labs` chip without a form submission.
- Removing that chip restored the canonical unfiltered URL. Typing `Product Manager` updated after the debounce to `?job_search=Product+Manager` and returned `3 open roles`.
- Selecting Full time and Part time together produced `?employment_type=full_time%2Cpart_time`, returned the OR-combined four matching roles, changed the menu summary to `Job type · 2`, and displayed individually removable chips. Removing Full time preserved Part time in the URL and results.
- Clicking outside a menu closes it; Escape closes it and restores focus to its summary.
- Selecting Marketing preserved focus on the checked `marketing-northstar-labs` checkbox after the router update; it did not move focus to the first Department option.
- Pagination uses equal 42px page buttons, a wider text action for Previous/Next, no inherited link underlines, a clear contrast-color current state, and consistent hover/focus treatments. Page 2 correctly moves the current state and exposes Previous.
- The server-rendered GET forms, real clear links, and submit controls remain in the markup; JavaScript hides only the filter submit fallback after the Interactivity API module starts.
- Browser console: no errors or warnings.
- PHP syntax, JavaScript syntax, block metadata validation, PHP 7.4 compatibility, and `git diff --check`: passed. Focused PHP coding standards: 0 errors; 9 existing public-query advisories.

**Comparison history**

1. First responsive review finding (P2): the separate Search and Filters blocks retained the theme's block gap, making the intended shared panel look disconnected; the Department label also clipped at 390 px.
2. Fix: strengthened the adjacent-block margin rule and tightened narrow-screen select padding/type size.
3. Post-fix evidence: `docs/audits/2026-07-31-job-filter-chips/04-implementation-mobile.jpg` and `docs/audits/2026-07-31-job-filter-chips/05-comparison.png`; the panel is continuous, labels are readable, and horizontal overflow remains absent.
4. User-review finding (P2): native select arrows sat too close to the right edge, long values ran into the arrow area, and each category accepted only one value.
5. Fix: replaced the three categorical selects with compact disclosure menus containing native checkboxes, added 42–46px reserved trailing space with arrows inset 14–16px, implemented OR-based multi-value query parsing, and made each selected value independently removable.
6. Post-fix evidence: `docs/audits/2026-07-31-job-filter-chips/07-multi-select-open.jpg`, `docs/audits/2026-07-31-job-filter-chips/08-multi-select-mobile.jpg`, and `docs/audits/2026-07-31-job-filter-chips/09-multi-select-comparison.png`; desktop and mobile geometry is clear, two-value selection works, and no horizontal overflow is present.
7. User-review finding (P2): after selecting a checkbox, focus restoration remembered only the filter category and moved to its first option.
8. Fix: pass the initiating checkbox value through router navigation and restore focus by both normalized field name and exact value.
9. Post-fix evidence: the live Marketing selection retained focus on the checked `department[]` control with value `marketing-northstar-labs`; browser console remained clear.
10. User-review finding (P2): pagination mixed one oversized current button with underlined text links because the shared `page-numbers` class styled both the list and its children as flex containers.
11. Fix: separated list, list-item, link, and current-page selectors; normalized all controls to a 42px height, centered the group, removed theme underline leakage, and added theme-token hover/focus/current states.
12. Post-fix evidence: `docs/audits/2026-07-31-job-filter-chips/13-pagination-comparison.png` and `docs/audits/2026-07-31-job-filter-chips/12-pagination-mobile.jpg`; geometry is consistent at desktop and 390px with no horizontal overflow.

**Follow-up polish**

- Themes can tune control surfaces, active colors, focus color, radius, and gap through the documented `--llamahire-*` properties without changing markup or behavior.

final result: passed

---

# Applications Inline Review Design QA

**Source visual truth**

- User-selected option 1: `docs/audits/2026-08-10-applications-inline-review/01-selected-direction.png`
- Source dimensions: 1487 × 1058 px at 1× density. This is the selected composition; the later append-only note-history behavior was explicitly approved in conversation.

**Implementation evidence**

- Live Studio page: `http://localhost:8896/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-applications`
- Final aligned browser capture: `docs/audits/2026-08-10-applications-inline-review/10-notes-history-aligned.png`
- Equal-size side-by-side comparison: `docs/audits/2026-08-10-applications-inline-review/11-notes-history-aligned-comparison.png`
- Approved two-row implementation: `docs/audits/2026-08-10-applications-inline-review/13-two-row-review-desktop.png`
- Notes modal: `docs/audits/2026-08-10-applications-inline-review/14-notes-history-modal.png`
- Narrow stacked state: `docs/audits/2026-08-10-applications-inline-review/15-two-row-review-narrow.png`
- Browser viewport: 1488 × 1059 CSS px at 1× density; captured implementation: 1473 × 1048 px after browser scrollbar/chrome allocation. It was normalized to 1487 × 1058 px for the combined comparison.
- State: authenticated WordPress administrator with Avery Chen's review expanded; latest activity and latest note visible, full histories available in modals.

**Full-view comparison evidence**

- The production screen follows the selected composition while incorporating the approved refinement: a compact status/latest-activity summary row followed by a two-column materials/notes row.
- The expanded panel does not repeat candidate, contact, or job information already visible in the row.
- Application materials now use one consistent verb: `View` for readable PDF and cover-letter content, and `Download` for file retrieval. DOC and DOCX files remain download-only.
- Review separates status saving from note creation. The panel shows only the newest note and newest activity entry; bounded newest-first histories open in focused modals.
- The implementation uses WordPress admin typography, controls, Dashicons, colors, and DataViews composition instead of reproducing the illustrative admin shell.

**Focused comparison evidence**

- The combined image keeps materials, review, activity, row attachment, borders, and action hierarchy readable at equal pixel dimensions, so no additional crop was needed.
- The approved two-row implementation is shorter than the intermediate three-column history design because full histories have moved into modals.

**Required fidelity surfaces**

- Fonts and typography: WordPress admin system typography, core control sizes, weights, and muted metadata hierarchy remain consistent with the selected native-admin direction.
- Spacing and layout rhythm: two balanced rows, consistent central dividers, material cards, status section, composer, latest-note card, and modal lists use a compact 14–22 px rhythm without desktop clipping.
- Colors and visual tokens: core admin blue, neutral borders, muted metadata, and semantic green success text use WordPress tokens and retain sufficient contrast.
- Image quality and asset fidelity: no raster product imagery is present; file and cover-letter indicators use WordPress Dashicons rather than approximated assets.
- Copy and content: `View`, `Download`, `Save status`, `Add private note`, `Add note`, `Private notes`, and `Recent activity` accurately describe their distinct behavior.

**Findings and refinements**

1. Initial comparison finding (P2): programmatic focus placed a heavy double-blue focus ring around the entire expanded panel, making the row attachment look visually harsher than the selected direction.
2. Fix: left focus on the disclosure button after expansion while retaining a keyboard-only focus treatment on the review region. The final panel uses the selected thin blue border.
3. Initial comparison finding (P3): default application rows were taller than the selected compact list.
4. Fix: reduced default, compact, and comfortable vertical padding while retaining all three DataViews density choices.
5. File-actions follow-up finding (P2): the first narrow review capture retained a 980 px table minimum width, clipping the right edge of the expanded review at the available 873 px content width.
6. Fix: reduced the desktop table minimum to 860 px. The matched desktop capture retains the selected three-column composition, while the narrow capture uses the intended two-column review layout without clipping.
7. User follow-up finding (P2): `Preview` for PDFs and `Open` for cover letters described the same read action inconsistently, and the single saved-notes textarea implied destructive replacement.
8. Fix: standardized readable materials to `View`; split status and note actions; introduced an append-only, newest-first note list with a blank composer, author, timestamp, migration, ownership checks, privacy export, and erasure coverage.
9. Post-fix evidence: `10-notes-history-aligned.png` and `11-notes-history-aligned-comparison.png`; the composer clears after adding, both notes remain visible, and the latest activity reflects the addition without copying note content.
10. User refinement (P2): three equal columns gave status, notes, materials, and activity similar visual weight and made the expanded row unnecessarily tall.
11. Fix: moved Status and the latest activity into the summary row; placed Application materials and Notes below; limited the inline histories to one entry each; added full activity and private-notes modals.
12. Post-fix evidence: `13-two-row-review-desktop.png`, `14-notes-history-modal.png`, and `15-two-row-review-narrow.png`. Desktop hierarchy is compact and clear, modal histories are readable, and the stacked state has no document-level horizontal overflow.
13. No actionable P0, P1, or P2 visual differences remain.

**Interaction and quality checks**

- Expanding and collapsing the first application: passed.
- Protected résumé View and Download actions and cover-letter modal View/Close flow: passed.
- Adding `Follow up with design portfolio.` appended a second note, retained the original fixture note, attributed the new note to admin, cleared the composer, and added a content-free activity event.
- Status saving remains a separate action.
- Latest activity summary, full activity modal, latest-note summary, and full notes modal: passed.
- Compiled asset build, PHP syntax, focused coding standards, PHP 7.4 compatibility, translation catalog generation, and `git diff --check`: passed.
- Browser console retained the page's pre-existing WordPress `core/rich-text` duplicate-registration message; no interaction error was produced by the inline review flow.
- Isolated smoke suite: 260 checks passed. The Playwright hiring workflow completed successfully after fixture setup.

final result: passed

---

# Job Card Metadata Chips Design QA

**Source visual truth**

- Selected ImageGen option: `/Users/mikeyarce/.codex/generated_images/019fdcbf-0813-7ea3-b1c4-15544ee7412f/exec-9f2c0f81-3f47-45dc-be50-9f6f536c93be.png`
- Source dimensions: 1448 × 1086 px. The source shows the same pale, rounded, non-interactive metadata chips in one-row and two-row card states.
- Selected Featured refinement: `/Users/mikeyarce/.codex/generated_images/019fdcbf-0813-7ea3-b1c4-15544ee7412f/exec-7fee6131-ec86-4ef9-9848-1392b87d3198.png`. It keeps every title on the same starting line, places a quiet uppercase label at the top-right, and distinguishes Featured cards with a darker neutral border.

**Implementation evidence**

- One-row state: `docs/audits/2026-08-07-job-card-metadata-chips/implementation-one-row.png`
- Two-row state: `docs/audits/2026-08-07-job-card-metadata-chips/implementation-two-row.png`
- Aligned card actions: `docs/audits/2026-08-07-job-card-metadata-chips/implementation-aligned-actions.png`
- Combined comparison: `docs/audits/2026-08-07-job-card-metadata-chips/comparison.png`
- Featured desktop state: `docs/audits/2026-08-07-job-card-metadata-chips/implementation-featured-final.png`
- Featured narrow state: `docs/audits/2026-08-07-job-card-metadata-chips/implementation-featured-mobile.png`
- Featured source/live comparison: `docs/audits/2026-08-07-job-card-metadata-chips/comparison-featured-final.png`
- One-row browser viewport: 620 × 900 CSS px at 1× density; capture: 605 × 878 px after browser chrome/scrollbar allocation.
- Two-row browser viewport: 1280 × 720 CSS px at 2× reported page density; browser capture: 1265 × 712 px. The in-app browser normalized the capture to CSS-like pixel dimensions.
- State: authenticated Twenty Twenty-Five careers page with the deterministic `Demo draft listing` fixture visible.

**Full-view comparison evidence**

- The live cards reproduce the selected direction's pale neutral chip surface, compact rounded corners, punctuation-free grouping, and understated relationship to the title.
- At a 543 px card width, all three metadata items share one row. At a 376 px card width, `Full Time` wraps as a whole chip to a second row with consistent left alignment and no orphan separator.
- Cards use a vertical flex layout so each row's `View role` links share the same baseline despite different title, metadata, and excerpt wrapping. The Featured label is removed from normal flow and anchored at the top-right, so Featured and standard titles retain the same starting position.
- Featured cards use a darker neutral border (`38%` ink mixed with the surface) while standard cards retain the lighter theme border (`15%` ink mixed with the surface). The distinction is visible without adding a new layout row or decorative accent color.
- The live page retains the active WordPress theme's typography, card proportions, and surrounding job-discovery layout rather than hardcoding the illustrative mock's larger type scale.

**Focused comparison evidence**

- The combined image places the selected source beside both responsive live states. Chip shape, neutral surface, spacing, text order, and wrapping behavior are directly readable without a separate crop.
- Computed live styles use a 6 px radius, the existing `--llamahire-soft` theme token, 0.28em × 0.6em padding, and a 7 px × 8 px flex gap.

**Required fidelity surfaces**

- Fonts and typography: the existing 0.88rem metadata size, inherited theme family, muted color, and 1.3 line height remain readable and subordinate to the job title.
- Spacing and layout rhythm: complete chip units wrap through the existing flex layout; both responsive states keep even row and item gaps without punctuation artifacts.
- Colors and visual tokens: chip backgrounds use `--llamahire-soft`, which already adapts to the active WordPress theme; text continues to use `--llamahire-muted`.
- Image quality and asset fidelity: no raster imagery, logos, decorative marks, or custom icons are part of this selected treatment.
- Copy and content: location, workplace, and employment labels remain unchanged and in the same semantic order.

**Findings**

- No actionable P0, P1, or P2 differences remain.
- P3: the generated concept uses a slightly larger illustrative type scale. The implementation intentionally preserves the plugin's existing theme-inherited card typography.

**Interaction and quality checks**

- Card links and server-rendered metadata markup remain unchanged; only presentation changed.
- The 620 px state has zero document-level horizontal overflow.
- The three cards in each verified desktop row had identical card bottoms and link top/bottom positions. The 390 × 844 state retained compact badges and zero document-level horizontal overflow.
- All three Featured desktop headings had the same measured top position. At 390 × 844, `Product Manager` remained one line while longer titles wrapped cleanly with an 18.7 px measured gap before the label.
- Browser console: no errors or warnings.
- `git diff --check`: passed.

**Comparison history**

1. First live comparison: passed with no actionable P0, P1, or P2 findings; no corrective visual iteration was required.
2. Follow-up polish: converted cards to vertical flex containers and used an automatic top margin on the existing card link so actions align at the bottom without changing markup or interaction behavior.
3. Featured refinement: replaced the pill with a quiet top-right typographic label and darkened the card border. The first pass reserved 7rem beside the label and caused unnecessary narrow-card wrapping; the final pass reduced that reserve to 5rem, keeping a safe gap while allowing shorter titles to remain on one line.

**Follow-up polish**

- None required for this component.

final result: passed

---

# Job Facts Mockup Design QA

**Source visual truth**

- User-selected mockup: `/var/folders/vk/rsn6lrb97t19mrdsg80py_gr0000gn/T/codex-clipboard-5cda1c88-f785-4551-a435-ba4b95e3c248.png`
- Source dimensions: 1580 × 564 px. The selected target is the divided, text-first job-facts treatment; the surrounding dark preview shell is illustrative rather than a fixed theme requirement.

**Implementation evidence**

- Desktop: `docs/audits/2026-08-05-job-facts-mockup/04-implementation-desktop-final.png`
- Mobile: `docs/audits/2026-08-05-job-facts-mockup/06-implementation-mobile-final.png`
- Focused source/implementation comparison: `docs/audits/2026-08-05-job-facts-mockup/05-focused-comparison-final.png`
- Desktop browser viewport: 1280 × 900 CSS px at 1× density; capture: 1265 × 889 px after browser chrome/scrollbar allocation.
- Mobile browser viewport: 390 × 844 CSS px at 1× density; capture: 375 × 812 px after browser chrome/scrollbar allocation.
- State: authenticated draft preview for job 1677 in the Twenty Twenty-Five theme.

**Full-view comparison evidence**

- The implementation adopts the mockup’s text-only cells, subtle one-pixel dividers, rounded outer frame, compact uppercase labels, strong values, and generous internal padding.
- The active WordPress theme continues to own the light/dark palette and typography. The source’s dark shell was not hardcoded into the plugin.
- Populated company information remains as an additional full-width adaptive row because the production details block exposes organization as a configurable fact. Missing rows still collapse without reserved space.

**Focused comparison evidence**

- The combined comparison shows the same label/value hierarchy, border rhythm, cell separation, and text alignment at readable scale.
- The first implementation retained the longer `Employment type` label and wrapped it at the real content width. The final implementation uses the mockup’s `Employment` label and keeps it on one line.
- No raster imagery or decorative icons are present in the selected facts treatment. Image quality and custom-asset fidelity are therefore not applicable.

**Required fidelity surfaces**

- Fonts and typography: inherits the active theme while matching the source’s uppercase label hierarchy, stronger value weight, line height, and letter spacing.
- Spacing and layout rhythm: 20 × 22 px desktop cell padding, 17 × 16 px narrow padding, one-pixel dividers, and a 12 px outer radius reproduce the source’s density without crowding.
- Colors and visual tokens: surfaces, borders, text, and muted labels map to LlamaHire/WordPress theme tokens rather than hardcoded source colors.
- Image quality and asset fidelity: not applicable; the chosen component is deliberately text-only.
- Copy and content: `Location`, `Location type`, `Employment`, and `Posted` match the source vocabulary; real job values replace illustrative mock data.

**Findings**

- No actionable P0, P1, or P2 differences remain.
- Acceptable product difference: the automatic production panel retains Company as a full-width row when populated, while the illustrative source places company beside the title.

**Interaction and quality checks**

- Empty-fact filtering and adaptive wrapping remain server-rendered.
- Desktop and 390 px mobile states have no document-level horizontal overflow.
- Definition-list semantics and visible term/value reading order remain intact.
- Browser console: no errors or warnings.

**Comparison history**

1. First comparison finding (P2): icon-led production cells and sentence-case labels differed materially from the selected text-first mockup.
2. Fix: removed decorative fact icons, adopted divided text cells, uppercase labels, stronger values, consistent padding, and a rounded outer frame.
3. First post-fix comparison finding (P2): `Employment type` wrapped at the real content width while the source used `Employment` on one line.
4. Fix: aligned the frontend and block-editor label to `Employment`.
5. Post-fix evidence: `04-implementation-desktop-final.png`, `06-implementation-mobile-final.png`, and `05-focused-comparison-final.png` show the selected hierarchy with no crowding or overflow.

**Follow-up polish**

- None required for this component.

final result: passed

---

# Settings Option 1 Design QA

**Source visual truth**

- `docs/audits/2026-07-27-admin-design-options/settings-01-section-navigator.png`
- 1487 × 1058 px.

**Implementation evidence**

- Desktop: `docs/audits/2026-07-27-admin-design-options/settings-wordpress-standard-controls.png`
- Mobile Organization: `docs/audits/2026-07-27-admin-design-options/settings-wordpress-standard-controls-mobile.jpg`
- Mobile Notifications: `docs/audits/2026-07-27-admin-design-options/settings-notifications-refined-mobile.png`
- Notifications actions: `docs/audits/2026-07-27-admin-design-options/settings-notifications-refined-final.png`
- Side-by-side: `docs/audits/2026-07-27-admin-design-options/settings-wordpress-standard-controls-comparison.png`
- Desktop viewport: 1487 × 1058 CSS px, `devicePixelRatio: 1`; source and implementation were compared at equal pixel dimensions without density conversion.
- Mobile viewport: 390 × 844 CSS px, `devicePixelRatio: 1`.
- State: authenticated WordPress administrator, company-careers mode, Organization section active.

**Full-view comparison evidence**

- The implementation preserves the selected concept’s section navigator, one-section-at-a-time content model, two-column desktop field rows, WordPress-native controls, active blue indicator, and end-of-section save actions.
- WordPress’s real admin chrome is denser than the generated concept’s illustrative chrome. The application-owned content retains the intended hierarchy without overriding core admin sizing.
- A fabricated “last saved” timestamp was intentionally not implemented because the current settings model does not store one. The footer truthfully says changes apply when saved.

**Focused comparison evidence**

- Organization fields were compared in the combined image. The first pass used legacy stacked form rows; the final pass moves labels/help text left and controls right to match the source.
- Navigation was compared in the combined image. The final pass adds WordPress Dashicons, active state, and matching section order.
- The logo field uses the existing Media Library workflow and real saved logo. No asset was approximated.
- The Replace and Remove logo actions now use the same WordPress button vocabulary and core geometry. Remove retains a minimal destructive red treatment.
- Mobile was checked separately because the source did not define a mobile state. It uses a horizontally scrollable section navigator, single-column fields, and has zero document-level horizontal overflow.
- Text, email, URL, subject, select, and short-code controls use their proper HTML types plus WordPress's `regular-text`, `small-text`, and `large-text` classes. WordPress core now owns their dimensions and typography; long-form textareas retain content-appropriate heights. The delivery-test email field aligns to the same desktop control column.

**Findings**

- No actionable P0, P1, or P2 issues remain.
- P3: the generated source shows a stored save timestamp and slightly larger application-owned typography. The implementation keeps WordPress-native typography and does not invent unavailable state.
- Intentional refinement: the source’s sticky action bar was changed to an end-of-section action bar because it overlaid the middle of the longer Notifications form. Save actions now follow email previews and delivery diagnostics in document order.

**Interaction and quality checks**

- Section navigation, hash state, notification diagnostics visibility, and reset-unsaved-changes behavior were exercised in the browser.
- Reset restored the Organization name from a temporary unsaved value to `Northstar Labs`.
- Browser console: no LlamaHire errors. WordPress emitted its existing `core/rich-text` duplicate-store registration message from `wp-includes/js/dist/data.min.js`; it is unrelated to this Settings change.
- PHP syntax, JavaScript syntax, and `git diff --check`: passed.
- Smoke suite: 176 checks passed.
- Playwright suite: 7 tests passed.

**Comparison history**

1. First comparison finding (P2): fields remained vertically stacked and navigation lacked the source’s icon hierarchy.
2. Fix: introduced responsive two-column field rows, descriptive label copy, and WordPress Dashicons.
3. Post-fix evidence: `settings-option-1-comparison-final.png`; desktop hierarchy now matches the selected direction, and the 390 px capture remains overflow-free.
4. User-review finding (P1): typed and untyped inputs rendered at different heights, and the sticky actions overlaid long Notifications content.
5. Fix: applied shared 40px input/select sizing, aligned the delivery-test input to the form grid, moved actions after diagnostics in the DOM, and removed sticky positioning.
6. Post-fix evidence: `settings-option-1-refined-comparison.png`, `settings-notifications-no-overlay.png`, and `settings-notifications-refined-final.png`.
7. User-review finding (P2): the logo actions used different core button classes and rendered at 40px versus 32.15px with different padding and radii.
8. Fix: normalized both actions as a single 40px button group while retaining the destructive treatment for removal.
9. Post-fix evidence: `settings-logo-buttons-comparison-final.png`; desktop and 390px geometry both show equal heights and no horizontal overflow.
10. User-review finding (P2): form and button geometry was being duplicated in plugin CSS instead of relying on WordPress admin conventions.
11. Fix: added explicit HTML input types, standardized field-width classes by data type, put both logo actions on core button classes, and removed redundant width, height, padding, radius, and mobile-control overrides. Custom CSS now covers only LlamaHire's section layout, responsive navigation, media preview, action placement, and destructive-color semantics.
12. Post-fix evidence: `settings-wordpress-standard-controls.png` and `settings-wordpress-standard-controls-mobile.jpg`; Organization fields render at the same core size, application/privacy selects and page selectors use `regular-text`, and the 390px view remains overflow-free.

**Follow-up polish**

- Consider recording a real settings update timestamp in a future data-model change if “last saved” information becomes useful beyond decoration.

final result: passed

---

# Reject Candidate Confirmation Modal Design QA

**Source visual truth**

- Approved modal mock: `/Users/mikeyarce/.codex/generated_images/019fae7c-da14-7f50-ba05-d52273ed20f5/call_5RkqhnRNOMsnWlZAsrN2Fwob.png`
- Source dimensions: 1456 × 1080 px at 1× density.

**Implementation evidence**

- Hiring page: `http://localhost:8896/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-hiring&job_id=all&application=281`
- Final desktop capture: `docs/audits/2026-07-30-reject-modal/03-reject-modal-matched-state.jpg`
- Side-by-side comparison: `docs/audits/2026-07-30-reject-modal/04-reject-modal-comparison.png`
- Implementation capture dimensions: 1265 × 712 px at 1× density.
- State: authenticated WordPress administrator, all job titles, Owen Brown selected, rejection confirmation open.
- For the comparison, the 1456 × 1080 source was proportionally normalized to 960 × 712 so the two states share the same visible height.

**Visual comparison**

- The implementation reproduces the approved modal structure: concise question heading, candidate and job-specific consequence copy, close control, secondary Cancel action, destructive Reject candidate action, and a dimmed Hiring workspace behind it.
- Dialog width, corner radius, elevation, internal spacing, footer separation, and action alignment match the selected direction while retaining WordPress-native typography and control behavior.
- The modal remains centered and fully contained at a 390 × 844 mobile viewport.
- No custom imagery was required. The close affordance uses the existing WordPress icon family.

**Interaction and quality checks**

- The drawer's Reject candidate action opens the confirmation without changing candidate data.
- Cancel, the close control, backdrop dismissal, and Escape close the dialog.
- The confirmed action remains a nonce-protected server-backed form.
- The destructive submit was intentionally not exercised so the demo candidate would not be moved during visual QA.
- The removed synthetic Next step field is absent from both candidate cards and the candidate drawer.
- Browser console: no errors or warnings.
- PHP syntax, JavaScript syntax, and `git diff --check`: passed.

**Findings**

- No actionable P0, P1, or P2 issues remain.
- Intentional difference: the implementation preserves WordPress admin chrome and native text rendering rather than reproducing the illustrative shell pixel-for-pixel.

final result: passed

---

# Hiring Interaction Polish Design QA

**Source visual truth**

- `/Users/mikeyarce/.codex/generated_images/019fae7c-da14-7f50-ba05-d52273ed20f5/call_LT3x53tgMC9BTApzXUKed1cC.png`
- Source dimensions: 1487 × 1058 px at 1× density.

**Implementation evidence**

- Default board: `docs/audits/2026-07-30-hiring-interaction-polish/02-default-no-selection.png`
- Saved candidate state: `docs/audits/2026-07-30-hiring-interaction-polish/03-save-confirmation.png`
- Final restored board: `docs/audits/2026-07-30-hiring-interaction-polish/04-final.png`
- Default-state comparison: `docs/audits/2026-07-30-hiring-interaction-polish/05-comparison.png`
- Drawer-state comparison: `docs/audits/2026-07-30-hiring-interaction-polish/06-drawer-comparison.png`
- Browser viewport and implementation captures: 1147 × 851 CSS/physical px at 1× density.
- Comparison normalization: the 1487 × 1058 source was proportionally resized to 1196 × 851; implementation captures remained 1147 × 851. The two equal-height images were combined side by side.
- State: authenticated WordPress administrator, company mode, Senior Product Designer selected. Default comparison has no candidate selected; focused comparison has Avery Chen 4 selected with the save confirmation visible.

**Full-view comparison evidence**

- The initial board now opens without selecting a candidate or reserving drawer space. This intentionally differs from the source's demonstrated selected state because a candidate panel should only open in response to a user choice.
- The header, compact filters, five-stage board, stage treatments, card hierarchy, empty states, and overall density remain aligned with the selected visual direction.
- The ambiguous ellipsis was removed from the filter row. Its actual destination is now a clearly labeled `View applications` action in the page header.
- The final board has no document-level horizontal overflow at the verified viewport.

**Focused comparison evidence**

- Candidate cards retain the source's name, role, applied date, time-in-stage, next-step, and initials hierarchy.
- The visible per-card `Move` form was removed. A small WordPress move icon now communicates the primary drag affordance, while the selected-candidate drawer retains its explicit stage controls for accessible non-drag movement.
- The drawer comparison confirms the selected outline, identity and contact hierarchy, stage and note fields, primary next-stage action, fallback movement control, and rejection action.
- The new green `Changes saved.` status sits directly above the editable fields and was verified visible after a real form submission.
- WordPress admin typography, Dashicons, native controls, and existing color tokens remain intact. No image assets or custom-drawn icon approximations were introduced.

**Interaction and quality checks**

- Fresh Hiring URL: no candidate drawer and no selected card.
- Candidate card click: opens the corresponding drawer and selected state.
- Save changes: submitted successfully and exposed a visible `Changes saved.` status; the fixture note content was unchanged.
- Drag-and-drop: Avery Chen 3 moved from Offer to Interviewing, stage counts changed from `0/1` to `1/0`, and the candidate was dragged back to restore the original `0/1` state.
- Filter ellipsis count: 0. Per-card Move control count: 0. Page-header applications action count: 1.
- Browser console: no errors or warnings.
- PHP syntax, JavaScript syntax, compiled Applications asset build, and `git diff --check`: passed.

**Findings**

- No actionable P0, P1, or P2 issues remain.
- P3: the mock uses synthetic card quantities and activity content that differ from the deterministic fixtures. This is data variation, not visual drift.
- Intentional difference: the mock opens with a selected candidate to demonstrate the drawer; the implemented default is now unselected to match the interaction model requested by the user.

**Comparison history**

1. User-review finding (P1): the Hiring page preselected a candidate even though the user had not chosen one.
2. User-review finding (P2): each card exposed both drag-and-drop and a visible Move control.
3. User-review finding (P2): saving candidate changes returned without visible confirmation.
4. User-review finding (P2): the filter-row ellipsis was misaligned and its purpose was unclear.
5. Fix: require an explicit `application` selection before rendering the drawer; remove card-level Move forms; replace card ellipses with drag affordances; add an in-drawer saved status; move the applications-list action into the header with a clear label; simplify the responsive filter grid.
6. Post-fix evidence: `05-comparison.png` and `06-drawer-comparison.png`; both default and selected states were checked, and all four reported issues are resolved.

final result: passed

---

# Shared Dashboard and Hiring View Design QA

**Source visual truth**

- Shared operations direction: `/Users/mikeyarce/.codex/generated_images/019fae7c-da14-7f50-ba05-d52273ed20f5/call_RC7GKCpieMKLYSqnnBLRitlT.png`
- Selected Hiring direction: `/Users/mikeyarce/.codex/generated_images/019fae7c-da14-7f50-ba05-d52273ed20f5/call_LT3x53tgMC9BTApzXUKed1cC.png`
- Hiring source dimensions: 1487 × 1058 px at 1× density.

**Implementation evidence**

- Company shared dashboard: `http://localhost:8896/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-dashboard`
- Company Hiring pipeline: `http://localhost:8896/wp-admin/edit.php?post_type=llamahire_job&page=llamahire-hiring`
- Community-board dashboard: the same shared dashboard URL with job-board mode active.
- Initial Hiring capture: `docs/audits/2026-07-29-dashboard-hiring/hiring-redesign-implementation.png`
- Final Hiring capture: `docs/audits/2026-07-29-dashboard-hiring/hiring-redesign-final.png`
- Final combined comparison: `docs/audits/2026-07-29-dashboard-hiring/hiring-redesign-comparison-final.png`
- Final in-app browser viewport and capture: 1020 × 914 CSS/physical px at 1× density.
- For the combined comparison, the 1487 × 1058 source was proportionally normalized to 1285 × 914 so source and implementation share the same visible height. The source has no 1020 px responsive state; the implementation therefore shows its narrower desktop/tablet interpretation with horizontally scrollable stages.
- State: authenticated WordPress administrator, company mode, Senior Product Designer selected, Avery Chen selected in Reviewing, drawer open.
- Data: deterministic demo fixtures with 16 jobs and 64 applications across every pipeline stage.

**Full-view comparison evidence**

- The shared view preserves the selected queue-first direction: a compact attention queue, at-a-glance metrics, active jobs/listings, and recent operational activity.
- Community-board mode changes the heading, explanatory copy, primary queue, metrics, and listing context. It reports live listings, moderation work, active employers, and application volume; the ATS-specific Hiring menu is intentionally absent for a board-wide operator.
- The revised Hiring view now matches the source's primary composition: one selected job, compact toolbar, five lightly bordered stage lanes, colored stage labels, detailed candidate cards, selected-card outline, and a full-height right action drawer.
- At the narrower verified viewport, three readable lanes remain visible while Offer and Hired are available through horizontal board scrolling. The drawer remains fixed and the page itself has no document-level horizontal overflow.

**Focused comparison evidence**

- Candidate cards now match the source's scan order: candidate name, role, applied date, time in stage, next step, initials, and a compact drag affordance.
- The drawer now matches the source hierarchy: identity/contact, resume, stage, next step, private note, activity, primary next-stage action, move menu, and rejection action.
- WordPress Dashicons provide the visible UI icon family. There are no handcrafted SVGs, CSS illustrations, emoji, or placeholder image assets.
- Typography uses WordPress admin's system stack and native control geometry; weights, compact 11–13 px metadata, and 22 px drawer heading reproduce the source hierarchy without overriding core admin chrome.
- Stage colors, neutral lane surfaces, blue selection, borders, and action colors visibly map to the reference palette.

**Interaction and quality checks**

- Candidate search was exercised in the browser and returned matching candidates with correct per-stage counts.
- Drag-and-drop was exercised in the browser: Avery Chen moved from Reviewing to New, the persisted stage counts changed from `0/2/0/1/0` to `1/1/0/1/0`, then Avery was dragged back to Reviewing to restore the final state.
- Cards use drag-and-drop as the board interaction; the selected-candidate drawer retains native stage controls as the accessible non-drag fallback.
- The candidate drawer can be closed, and its stage, note, next-stage, move, and rejection forms remain server-backed.
- Company and community-board states were exercised against the same shared dashboard.
- Company mode was restored after the alternate-state test.
- Active Jobs now exposes separate New, Reviewing, and Interviewing counts instead of the ambiguous combined “active” label.
- No document-level horizontal overflow was present on the shared dashboard or final Hiring state.
- Browser console: no errors or warnings.
- PHP syntax, JavaScript syntax, asset build, and `git diff --check`: passed.
- Compiled Applications assets: passed.
- Smoke suite: 179 checks passed.

**Findings**

- No actionable P0, P1, or P2 issues remain.
- P3: the mock includes Add candidate links, note-edit links, and a richer synthetic activity history. These were not fabricated because the current product has no manual-candidate creation or separate next-step/note event model.
- Intentional difference: WordPress admin chrome and native controls are retained rather than reproducing the illustrative shell.

**Comparison history**

1. User-review finding (P1): the first Hiring implementation looked substantially less polished than the selected visual and omitted expected drag-and-drop.
2. Fix: changed the default from an all-jobs backlog to one active job; rebuilt lanes, cards, filters, drawer proportions, status treatments, empty states, and sticky actions from the selected reference.
3. Fix: added persisted pointer and HTML5 drag-and-drop through an ownership-checked AJAX endpoint while retaining server-backed stage controls in the candidate drawer.
4. User-review finding (P2): Active Jobs used an ambiguous aggregate label, “active.”
5. Fix: replaced it with explicit New, Reviewing, and Interviewing counts.
6. First post-fix comparison finding (P2): selected-job cards omitted the role line and a visible drag instruction pushed the board farther from the source rhythm.
7. Fix: restored the role on every card and made the drag instruction screen-reader-only.
8. Post-fix evidence: `hiring-redesign-comparison-final.png`; the source and implementation share the same information hierarchy, lane/card language, action drawer, and selected state. The remaining column-count difference is the expected response to the narrower verified viewport.

final result: passed

---

# Current Result

The later Hiring Interaction Polish QA above supersedes the earlier selected-by-default state. The current default has no candidate drawer, card movement is drag-first with drawer stage controls as the accessible fallback, Save exposes an in-context success status, and the former filter ellipsis is now a labeled page-header action. The Reject Candidate Confirmation Modal QA records the current destructive-action flow: no synthetic Next step field, no immediate rejection, and a dismissible confirmation before the server-backed action.

final result: passed
