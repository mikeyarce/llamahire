# LlamaHire full screen-by-screen review

Date started: July 26, 2026

Method: review one screen with the product owner, record feedback and decisions, then continue to the next screen in journey order.

## Review order

1. First-run setup
2. Jobs dashboard
3. Jobs list
4. Add/edit job
5. Departments
6. Settings
7. Careers page
8. Jobs directory and filtering
9. Job detail and application form
10. Application success and error states
11. Recruiter applications inbox
12. Candidate detail and review
13. Activity
14. Employer submission
15. Employer My Jobs
16. Relevant empty, narrow, and permission-limited states

The inventory may expand when a reviewed screen exposes another distinct state.

## Screen 1 — First-run setup

- Screenshot: [01-first-run-setup.png](01-first-run-setup.png)
- Viewport: 1280 × 720 requested; 1265 × 720 captured after browser scrollbar allocation.
- State: company careers site selected with the Northstar demo identity populated.
- General health: functional and readable; awaiting product-owner design feedback.
- Visible strengths: the purpose choice is first, the selected mode is explained in plain language, and identity fields use familiar WordPress controls.
- Visible risks to discuss: the page has a large amount of unused horizontal space, the progress indicator is visually quiet, the form continues well below the fold, and the organization-logo controls are partially below the initial viewport.
- Accessibility evidence limit: screenshot review can assess visible hierarchy and approximate contrast, but not keyboard order, focus visibility, announcements, or assistive-technology output.
- Product-owner feedback:
  - The current progress treatment implies steps, but the screen does not explain them or behave like a step-by-step flow.
  - Replace the single long form with a clearly numbered four-step experience:
    1. Site purpose
    2. Organization or job-board identity and defaults
    3. Candidate privacy and hiring inbox
    4. Careers page and final review
  - Show both “Step X of 4” and the current step name; make Back and Continue actions clear.
  - Restyle “Remove logo” so it has the same control height and visual rhythm as “Replace logo,” while remaining a secondary destructive action.
- Decision: revise this screen before continuing the review.
- Selected direction: option 3, using a compact horizontal four-step tracker and a focused two-column identity screen.
- Revised desktop screenshot: [01-first-run-setup-revised.png](01-first-run-setup-revised.png)
- Revised mobile screenshot: [01-first-run-setup-revised-mobile.png](01-first-run-setup-revised-mobile.png)
- Revised job-board identity screenshot: [01-first-run-setup-job-board-revised.png](01-first-run-setup-job-board-revised.png)
- Revised Careers-page step screenshot: [01-first-run-setup-step-4-revised.png](01-first-run-setup-step-4-revised.png)
- Source/implementation comparison: [01-first-run-setup-option3-comparison.png](01-first-run-setup-option3-comparison.png)
- Implementation notes:
  - Setup now behaves as four real steps with Back and Continue controls that preserve entered values.
  - The logo actions have matching 32px heights, while Remove logo remains visually destructive.
  - Job defaults remain available in a collapsed disclosure so identity stays focused.
  - Server-side validation returns users to the relevant step.
  - The mobile tracker shows all four steps without page-level or tracker overflow.
  - Job-board mode no longer asks for a duplicate website URL; it uses the current WordPress site URL. Company mode keeps an optional canonical organization URL for careers sites hosted separately from the main company website.
  - Step 4’s page-title input and existing-page selector now use the same 560 × 40px WordPress control treatment.
- Verification: 174 smoke checks and 7 end-to-end checks passed; live desktop/mobile browser QA found no console errors.
- Review status: revised screen ready for product-owner approval before moving to screen 2.
