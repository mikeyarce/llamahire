---
version: alpha
name: LlamaHire
description: A calm, trustworthy, WordPress-native hiring experience for employers and candidates.
colors:
  primary: "#2271b1"
  primary-hover: "#135e96"
  focus: "#3858e9"
  on-primary: "#ffffff"
  canvas: "#f0f0f1"
  surface: "#ffffff"
  surface-subtle: "#f6f7f7"
  on-surface: "#1d2327"
  secondary-text: "#50575e"
  muted-text: "#646970"
  border: "#dcdcde"
  border-strong: "#c3c4c7"
  danger: "#b32d2e"
  danger-hover: "#8a2424"
  danger-surface: "#fff5f5"
  success: "#14532d"
  success-surface: "#edfaef"
  warning: "#6e4c00"
  warning-surface: "#fcf0d5"
  status-new-background: "#e7f1ff"
  status-new-text: "#0a4b78"
  status-interviewing-background: "#f1eafe"
  status-interviewing-text: "#5936a2"
  status-offer-background: "#e0f5f3"
  status-offer-text: "#17645d"
  status-rejected-background: "#fce8e8"
  public-accent-fallback: "#19172c"
  public-on-accent-fallback: "#ffffff"
  public-surface-fallback: "#ffffff"
  public-ink-fallback: "#19172c"
  public-muted-fallback: "#666278"
  public-line-fallback: "#e7e5ee"
  public-soft-fallback: "#f7f6fb"
typography:
  admin-page-title:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: 28px
    fontWeight: 600
    lineHeight: 1.2
  admin-section-title:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: 18px
    fontWeight: 600
    lineHeight: 1.3
  admin-body:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: 14px
    fontWeight: 400
    lineHeight: 1.5
  admin-label:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: 14px
    fontWeight: 600
    lineHeight: 1.4
  admin-metadata:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
    fontSize: 12px
    fontWeight: 400
    lineHeight: 1.5
  public-heading:
    fontFamily: inherit
    fontSize: 32px
    fontWeight: 700
    lineHeight: 1.2
  public-body:
    fontFamily: inherit
    fontSize: 16px
    fontWeight: 400
    lineHeight: 1.55
  public-label:
    fontFamily: inherit
    fontSize: 14px
    fontWeight: 650
    lineHeight: 1.4
rounded:
  none: 0px
  admin: 2px
  sm: 4px
  control: 8px
  panel: 12px
  public-card: 16px
  pill: 999px
spacing:
  none: 0
  micro: 2px
  xs: 4px
  sm: 8px
  md: 12px
  lg: 16px
  section-sm: 20px
  xl: 24px
  2xl: 32px
  3xl: 48px
components:
  admin-canvas:
    backgroundColor: "{colors.canvas}"
    textColor: "{colors.on-surface}"
    typography: "{typography.admin-body}"
    rounded: "{rounded.none}"
    padding: "{spacing.section-sm}"
  admin-panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    typography: "{typography.admin-body}"
    rounded: "{rounded.admin}"
    padding: "{spacing.xl}"
  admin-subtle-panel:
    backgroundColor: "{colors.surface-subtle}"
    textColor: "{colors.secondary-text}"
    typography: "{typography.admin-body}"
    rounded: "{rounded.admin}"
    padding: "{spacing.lg}"
  admin-muted-text:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.muted-text}"
    typography: "{typography.admin-metadata}"
  admin-primary-button:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    typography: "{typography.admin-label}"
    rounded: "{rounded.admin}"
    padding: 6px 12px
    height: 30px
  admin-primary-button-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.on-primary}"
  admin-focus-indicator:
    backgroundColor: "{colors.focus}"
    textColor: "{colors.surface}"
  admin-divider:
    backgroundColor: "{colors.border}"
    textColor: "{colors.on-surface}"
    height: 1px
  admin-divider-strong:
    backgroundColor: "{colors.border-strong}"
    textColor: "{colors.on-surface}"
    height: 1px
  admin-danger-action:
    backgroundColor: "{colors.danger-surface}"
    textColor: "{colors.danger}"
    typography: "{typography.admin-label}"
    rounded: "{rounded.admin}"
    padding: 6px 12px
  admin-danger-action-hover:
    backgroundColor: "{colors.danger-surface}"
    textColor: "{colors.danger-hover}"
  admin-success-notice:
    backgroundColor: "{colors.success-surface}"
    textColor: "{colors.success}"
    typography: "{typography.admin-body}"
    rounded: "{rounded.sm}"
    padding: "{spacing.md}"
  admin-warning-notice:
    backgroundColor: "{colors.warning-surface}"
    textColor: "{colors.warning}"
    typography: "{typography.admin-body}"
    rounded: "{rounded.sm}"
    padding: "{spacing.md}"
  status-new:
    backgroundColor: "{colors.status-new-background}"
    textColor: "{colors.status-new-text}"
    typography: "{typography.admin-metadata}"
    rounded: "{rounded.pill}"
    padding: 2px 9px
  status-reviewing:
    backgroundColor: "{colors.warning-surface}"
    textColor: "{colors.warning}"
    typography: "{typography.admin-metadata}"
    rounded: "{rounded.pill}"
    padding: 2px 9px
  status-interviewing:
    backgroundColor: "{colors.status-interviewing-background}"
    textColor: "{colors.status-interviewing-text}"
    typography: "{typography.admin-metadata}"
    rounded: "{rounded.pill}"
    padding: 2px 9px
  status-offer:
    backgroundColor: "{colors.status-offer-background}"
    textColor: "{colors.status-offer-text}"
    typography: "{typography.admin-metadata}"
    rounded: "{rounded.pill}"
    padding: 2px 9px
  status-hired:
    backgroundColor: "{colors.success-surface}"
    textColor: "{colors.success}"
    typography: "{typography.admin-metadata}"
    rounded: "{rounded.pill}"
    padding: 2px 9px
  status-rejected:
    backgroundColor: "{colors.status-rejected-background}"
    textColor: "{colors.danger-hover}"
    typography: "{typography.admin-metadata}"
    rounded: "{rounded.pill}"
    padding: 2px 9px
  public-primary-action:
    backgroundColor: "{colors.public-accent-fallback}"
    textColor: "{colors.public-on-accent-fallback}"
    typography: "{typography.public-label}"
    rounded: "{rounded.control}"
    padding: 11px 18px
    height: 44px
  public-card:
    backgroundColor: "{colors.public-surface-fallback}"
    textColor: "{colors.public-ink-fallback}"
    typography: "{typography.public-body}"
    rounded: "{rounded.public-card}"
    padding: "{spacing.xl}"
  public-muted-surface:
    backgroundColor: "{colors.public-soft-fallback}"
    textColor: "{colors.public-muted-fallback}"
    typography: "{typography.public-body}"
    rounded: "{rounded.panel}"
    padding: "{spacing.lg}"
  public-divider:
    backgroundColor: "{colors.public-line-fallback}"
    textColor: "{colors.public-ink-fallback}"
    height: 1px
---

# LlamaHire Design Contract

## Overview

LlamaHire should feel calm, capable, trustworthy, and native to WordPress. It
supports sensitive hiring work, so clarity and predictable behavior matter more
than decoration. Interfaces should be easy to scan, conservative with color,
and explicit about consequences.

LlamaHire has two related visual contexts:

- **WordPress admin:** operational, compact, and deliberately WordPress-native.
  Use WordPress controls and interaction conventions before creating custom UI.
- **Public and employer-facing pages:** theme-aware and slightly more spacious.
  Inherit the active theme's typography and core colors while retaining reliable
  structure, accessibility, and LlamaHire's semantic states.

The YAML tokens above are the normative fallback values. Runtime CSS is the
executable implementation. Admin interfaces should map to WordPress design
tokens and components where available. Public interfaces should map to
`theme.json` presets through the existing `--llamahire-*` custom properties,
using the public fallback tokens only when the theme supplies no equivalent.

## Colors

Admin screens use the WordPress neutral palette, WordPress blue for primary
interaction, and restrained semantic colors. White surfaces sit on the normal
WordPress gray canvas. Borders and subtle surface shifts provide separation;
large tinted regions should be rare.

Workspace and application-detail styles share `assets/css/admin-tokens.css`
through a stylesheet dependency. Its scoped `--lh-*` properties map to the
canonical colors above; reuse these properties for shared admin surfaces,
text, borders, and actions.

Public screens derive their accent, foreground, and surface colors from the
active theme. The `public-*-fallback` tokens describe the safe fallback palette,
not a mandate to override a site's brand.

Semantic colors have stable meanings:

- Blue identifies primary interaction and focus.
- Green identifies successful or completed states.
- Amber identifies attention or work in progress.
- Red identifies destructive actions, errors, or rejected states.
- Purple identifies interviewing; teal identifies offers.

Never use semantic colors only as decoration. Pair status color with a label,
icon from the existing WordPress icon system when useful, or explanatory copy.
All normal text must meet WCAG AA contrast. Focus indicators must remain visible
against both the canvas and component surface.

## Typography

Admin screens use the WordPress system font stack and WordPress-native control
typography. Reserve the 28px page title for one heading per page. Section titles
use 18px; routine headings and labels use 14px or 16px. Metadata uses 12px with
comfortable line height and must not carry essential meaning by size alone.

Public screens inherit the active theme's fonts. Do not load a LlamaHire-specific
webfont. Use the theme's established heading and body hierarchy, with the public
tokens as sizing guidance when the theme provides no usable scale.

Keep prose at a readable line length, normally no more than 68 characters per
line. Prefer sentence case. Use direct verbs for actions: “Save status,” “Add
note,” “Retry missing emails,” and “Erase application.”

## Layout

Use a 4px base rhythm and the named spacing tokens. Prefer 8px, 12px, 16px,
20px, and 24px gaps before introducing one-off values. Establish hierarchy in
this order: spacing, grouping, alignment, typography, divider, subtle surface,
border, then shadow.

Admin pages should use repeatable screen structures:

- **Collection:** page header, bounded filters or actions, then one data surface.
- **Detail:** a wide primary column and compact sidebar, stacking below 782px.
- **Workflow:** a board or task surface with one active context and clear state.
- **Configuration:** grouped settings with short explanations and predictable
  save actions.

Avoid filling wide screens merely because space exists. Main reading content
should stay near 68 characters, while data surfaces may use the available width.
At narrow widths, stack columns and actions without horizontal page scrolling.

Public layouts remain fluid and theme-compatible. Use container-aware grids and
minimum control heights of 42px; primary public form controls should generally
be 44–50px tall.

## Elevation & Depth

LlamaHire is primarily flat. Use borders, dividers, and subtle surface changes
for hierarchy. Standard cards do not need decorative shadows. Use a shallow
shadow only for transient overlays such as menus, popovers, modals, or drawers
that must clearly sit above the current surface.

Do not stack multiple elevated cards inside one another. Sections inside a card
should normally use spacing or a divider rather than another bordered card.

## Shapes

Admin UI uses WordPress-native small radii: 2px for ordinary panels and buttons,
4px where a slightly softer notice or compact element is helpful, and a pill
only for categorical badges.

Public UI may use 8px controls, 12px grouped surfaces, and 16px standalone cards.
Do not mix multiple radii within one component. Circular treatments are reserved
for avatars, indicators, and controls that are genuinely circular.

## Components

### Actions

- Each section has at most one visually primary action.
- Secondary actions use WordPress secondary buttons in admin and outlined or
  text actions in public UI.
- Destructive actions use red text or a restrained red surface, state the
  consequence, and require confirmation when data cannot be recovered.
- Disabled or unavailable actions must look unavailable and explain why when
  the reason is not obvious.

### Panels and sections

Use a panel for one coherent object or task. Inside it, group related content
with section headings and dividers. Do not turn every section or row into a
card. Sidebars should remain compact and contain metadata, status, and secondary
activity rather than duplicating the primary content.

### Action rows

An action row contains a title, a short consequence or helper sentence, its
control or confirmation, and an action aligned at the trailing edge on desktop.
Rows stack on narrow screens. Use this pattern for file replacement, retries,
privacy actions, and similar bounded operations.

### Status badges

Use the canonical status tokens everywhere applications appear. Badges contain
short categorical labels, use a pill shape, and never serve as the only record
of state. Do not invent a new color for the same status on another screen.

### Forms

Place labels above controls and helper or error text directly below the relevant
control. Use native controls or WordPress components where possible. Required,
error, success, disabled, hover, and focus states must remain visible. Preserve
the public no-JavaScript baseline; JavaScript may enhance but not replace the
server-rendered workflow.

### Notices and empty states

Notices explain what happened and what the user can do next. Empty states should
name what is absent and offer one relevant next action. Avoid celebratory or
promotional language in operational admin screens.

### Disclosures, modals, and drawers

- Use a disclosure for infrequent secondary controls that belong to the page.
- Use a modal for a focused decision, destructive confirmation, or bounded
  history that would interrupt the current task if displayed inline.
- Use a drawer for quick review while preserving collection context.
- Use a full page for deep review, multi-section work, or stable linking.

### Icons and imagery

Use Dashicons or WordPress component icons in admin. Do not mix icon families or
substitute emoji and text glyphs for UI icons. Public imagery should come from
the site or product context; do not add decorative illustration merely to fill
space.

## Do's and Don'ts

- Do use WordPress components and conventions before creating admin variants.
- Do inherit public typography and colors from the active theme.
- Do reuse the canonical spacing, status, action, notice, and panel patterns.
- Do keep sensitive candidate information visually quiet and clearly scoped.
- Do verify desktop and narrow layouts, focus visibility, and semantic states.
- Do update this file when a genuinely reusable pattern is introduced.
- Don't create a new token for a value that already has the same meaning.
- Don't use cards inside cards when spacing or a divider is sufficient.
- Don't let multiple primary actions compete within one section.
- Don't hide a destructive consequence behind vague copy such as “Remove.”
- Don't expose private data in screenshots, URLs, fixtures, logs, or examples.
- Don't force public pages into a fixed LlamaHire brand that conflicts with the
  site's theme.

## Public theme compatibility

Launch validation targets Twenty Twenty-Five (default and Midnight), Astra,
GeneratePress, and Hello Elementor (alone and with Elementor active). This is
a test matrix, not a claim that untested configurations are certified.
Public layouts must respond to their content container, including narrow
desktop columns. Theme spacing presets need explicit fallback values. Preserve
theme heading typography and content widths; avoid `!important` layout rules
that prevent Site Editor or theme customizations. Keep foreground and surface
colors paired, and verify muted text and controls in dark variations.
