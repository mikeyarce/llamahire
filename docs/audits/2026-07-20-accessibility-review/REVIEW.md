# Keyboard and screen-reader accessibility review

Reviewed: July 20, 2026

Scope: first-run setup, job authoring, candidate application, applications list, and recruiter review

Environment: local WordPress 7.0.2, Chromium, desktop and 400 px admin viewport

## Outcome

The core Milestone 1 workflow is keyboard-operable in the automated browser suite and the confirmed semantic, announcement, form-association, menu-order, and narrow-layout defects found during this pass are fixed. No release-blocking accessibility defect remains in the reviewed workflow.

This is a focused product pass, not a WCAG conformance claim. Actual screen-reader speech output, Windows screen readers, high-contrast mode, reduced motion, 320% zoom across third-party themes, and the full supported browser/WordPress matrix remain release validation work.

The broader manual pass is scheduled in the product plan as a Free 1.0 release-candidate gate. Its evidence will live in this repository; Figma is not required.

## Reviewed workflow and findings

1. **First-run setup**
   - Fixed the progress indicator reporting 100% before any setup work was complete. It now exposes `value="0"`, `max="3"`, and `aria-valuetext="Not complete"` while setup is pending.
   - Replaced duplicate setup/skip nonce IDs with unique control names.
   - Associated country, hiring inbox, privacy text, and page selectors with their help text through `aria-describedby`.
   - Made Careers-page controls conditionally enabled and required so inactive fields are not misleading to keyboard or assistive-technology users.
   - Setup validation errors are assertive alerts and receive focus after the redirect.
   - Corrected the Jobs submenu to follow the intended workflow order after WordPress registers the Departments taxonomy item.

2. **Job authoring**
   - Reviewed the editor-native sidebar panels, labels, notices, and preview link. Existing controls use WordPress components and remain keyboard reachable.
   - The Playwright workflow opens panels with Enter, changes structured fields, saves, reloads, publishes, and follows the public job result.
   - No new confirmed authoring defect was introduced by this review. The native date input still needs a focused manual interaction check across the supported browser matrix.

3. **Candidate application**
   - Application failures now use `role="alert"` and are focusable.
   - Resume format guidance is associated with the upload input.
   - The privacy disclosure is associated with the submit button.
   - Submission, error recovery, and public-page behavior remain covered by the browser workflow and smoke suite.

4. **Applications list**
   - Added an accessible label to the status-filter navigation and `aria-current="page"` to the selected filter.
   - Hid decorative separators from assistive technology and removed the trailing separator.
   - Added a screen-reader table caption and explicit column scopes.

5. **Recruiter review**
   - Save and notification-retry outcomes now render as polite status announcements.
   - The two-column review layout now collapses without horizontal overflow at a 400 px admin viewport.
   - The automated keyboard workflow focuses and activates Save changes, Export CSV, and the resume download without pointer-only interaction.

## Evidence

- `06-setup-fixed.png` — corrected pending progress and Jobs submenu order.
- `07-candidate-error-fixed.png` — visible candidate validation alert and associated form help.
- `08-applications-list-fixed.png` — corrected filters and accessible applications table.
- `09-job-editor-fixed.png` — editor sidebar readiness and hiring-status panels.
- `10-recruiter-review-fixed.png` — recruiter save confirmation and desktop layout.
- `11-recruiter-review-narrow.png` — recruiter cards stacking at 400 px.

Earlier numbered images in this folder preserve the defects and intermediate states found during the pass.

## Automated coverage added

- Setup progress semantics and unique nonce controls.
- Careers-page conditional-control state.
- Candidate form descriptions and assertive error state.
- Recruiter save status announcement.
- Applications filter current state and table caption.
- Smoke assertions for form associations and accessible error output.

## Evidence limits and follow-ups

- Chromium's in-app browser did not expose reliable manual Tab-focus movement during this session, so keyboard evidence comes from the existing Playwright keypress workflow plus DOM/role inspection.
- No screen-reader speech log or automated accessibility-engine report was captured. VoiceOver/NVDA/JAWS verification remains part of the broader compatibility pass.
- The block editor canvas did not paint its central content in the captured state; editor-sidebar semantics and interactions were still inspectable and are covered in Playwright.
- The applications table remains dense on very small screens. It is usable with horizontal page behavior today, but a purpose-built responsive list presentation belongs with the Milestone 3 bulk/search/filter work.
