# Settings and Dashboard design options

Date: July 27, 2026

The concepts are grounded in authenticated captures of the current local WordPress Settings and Dashboard screens. They preserve WordPress admin chrome, the Jobs submenu, LlamaHire's current data, and WordPress-native control conventions.

## Settings

1. [Section Navigator](settings-01-section-navigator.png) — selected and implemented. The responsive implementation uses one-section-at-a-time navigation, WordPress-native icons and form classes, explicit input types, core control and button sizing, two-column desktop rows, single-column mobile rows, Media Library logo controls, reversible unsaved-section reset behavior, and save actions after each section’s complete content rather than overlaid on long forms. Custom CSS is reserved for the section layout, responsive navigator, media preview, action placement, and destructive-color semantics. See the [WordPress-standard desktop capture](settings-wordpress-standard-controls.png), [mobile Organization capture](settings-wordpress-standard-controls-mobile.jpg), [Notifications action placement](settings-notifications-refined-final.png), and [current side-by-side comparison](settings-wordpress-standard-controls-comparison.png).
2. [Progressive Groups](settings-02-progressive-groups.png)
3. [Configuration Overview](settings-03-configuration-overview.png)

## Dashboard

4. [Action Queue](dashboard-01-action-queue.png)
5. [Hiring Pipeline](dashboard-02-hiring-pipeline.png)
6. [Operational Pulse](dashboard-03-operational-pulse.png)

Settings option 1 is complete and passed design QA.

The Dashboard product decision is now defined in the
[mode-aware dashboard specification](DASHBOARD-SPEC.md). The shared page is a
decision-and-exception workspace, while its content and primary action adapt for
company hiring administrators, job-board operators, and ownership-scoped
employers. Revised visual concepts should use that specification rather than
selecting one of the original generic options.
