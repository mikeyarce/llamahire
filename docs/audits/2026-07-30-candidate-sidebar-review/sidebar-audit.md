# Candidate Sidebar Review

## Audit scope

- Surface: Hiring pipeline candidate sidebar.
- User goal: understand one application, record internal context, and move the candidate through the hiring process without ambiguity.
- State reviewed: Owen Brown, Developer Experience Engineer, Reviewing stage, all job titles selected.

## Step 1 — Open candidate details

Health: needs refinement.

Evidence: `01-candidate-sidebar.jpg`

### Strengths

- Candidate name, job title, email, resume, and current stage are easy to find.
- The selected card and drawer clearly belong together.
- Email and resume are direct actions rather than passive text.
- Stage and private-note fields have programmatic labels.

### Risks

- “Next step” looks candidate-specific, but it is generated only from the current stage. Every Reviewing candidate receives “Review portfolio,” whether a portfolio exists or not. This is false precision.
- The stored application date is absent from the sidebar, even though it is important hiring context.
- Phone and cover letter are stored application fields but are not represented in this view. That makes the sidebar an incomplete application summary.
- “Updated July 29, 2026” is the application record’s general update time, not specifically the note’s update time. Its placement under Private note implies a precision the data does not provide.

## Step 2 — Review history and actions

Health: confusing action hierarchy.

Evidence: `02-sidebar-activity-and-actions.jpg`

### Strengths

- Recent activity provides useful stage-history context.
- Advancing to the next stage is prominent.
- Rejection is visually separated as destructive.

### Risks

- There are four stage-changing mechanisms on this screen: the Stage dropdown, “Move to Interviewing,” “Move candidate,” and drag-and-drop on the board.
- “Move candidate” is vague; it means “change stage,” not moving the person somewhere.
- “Move to Interviewing” is convenient, but it duplicates the Stage dropdown.
- Reject submits immediately with no visible confirmation step. This creates an avoidable destructive-action risk.
- Recent activity currently records stage changes, while note updates are not represented. The broad label “Recent activity” suggests a more complete history than the product stores.
- The sticky action area consumes a large portion of the drawer and competes with the history directly above it.

## Recommended information model

1. Header
   - Candidate name
   - Job title
   - Applied date
   - Current-stage badge

2. Application
   - Email
   - Phone when present
   - Resume
   - Cover letter when present, collapsed by default

3. Internal notes
   - Private note
   - Save note
   - Use “Candidate updated” for the existing timestamp, or add a real note-specific timestamp before labeling it “Note updated”

4. Stage history
   - Rename Recent activity to Stage history until other event types are recorded

5. Actions
   - Primary: “Advance to Interviewing”
   - Secondary: “Change stage…”
   - Destructive: “Reject candidate,” with confirmation

## Highest-impact changes

1. Remove “Next step” until it becomes a real editable or assigned task.
2. Remove the editable Stage dropdown from the note form; show the current stage as read-only context.
3. Rename “Move candidate” to “Change stage…” and retain it as the single fallback for moving backward or skipping stages.
4. Keep the next-stage button as the primary shortcut.
5. Add confirmation before rejection.
6. Add applied date, phone, and cover-letter access so the sidebar represents the application more completely.

## Accessibility evidence limits

- The captured DOM confirms labels for Search candidates, Job title, Stage, Private note, Save changes, and the candidate actions.
- Screenshots cannot establish complete keyboard order, focus visibility during every interaction, screen-reader announcements after stage changes, or zoom/reflow behavior. Those require interaction testing after the sidebar is revised.
