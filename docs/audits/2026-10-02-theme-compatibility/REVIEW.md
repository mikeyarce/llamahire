# Launch theme compatibility pass

Date: October 2, 2026
Environment: disposable wp-env, WordPress 7.1.2, PHP 8.3.35, Chromium.
Studio desktop/mobile verification used the existing Twenty Twenty-Five site.

## Tested configurations

| Theme | Version | Configuration | Automated result |
|---|---|---|---|
| Twenty Twenty-Five | 1.5 | Default | Pass |
| Twenty Twenty-Five | 1.5 | Midnight | Pass |
| Astra | 4.14.0 | Default | Pass |
| GeneratePress | 3.6.1 | Default | Pass |
| Hello Elementor | 3.5.1 | Elementor inactive | Pass |
| Hello Elementor | 3.5.1 | Elementor 4.3.3 active | Pass |

The six cases passed at 1440px and 360px. Coverage includes the full Careers
page, hero/featured patterns, department landing page, jobs archive, single
job/application form, employer registration, and authenticated employer My
Jobs, Account, and Submit a Job. Additional checks cover a 600px job column,
empty search results, rendered error/success notices, a single application form,
page overflow, and 4.5:1 contrast for metadata labels, form help, required labels,
and the submit button.

The report contains version attachments and full-page screenshots. Mobile
single-job screenshots were visually reviewed across Midnight, Astra,
GeneratePress, and Hello with Elementor; Studio job and archive views were
also inspected, including the single-job view at 360px.

## Defects resolved

- Hello's wide alignment overflowed the Careers pattern by 70px at 360px.
  Scoped pattern width constraints now keep the supplied content inside its
  available column without a theme-specific patch.
- Mobile job-fact dates could be clipped by a nowrap rule. Dates and long fact
  values now wrap.
- Muted form help in Midnight measured 4.26:1 during the contrast checks.
  A stronger theme-derived muted token passes the selected 4.5:1 checks.
- The single-job layout assumed a wide viewport rather than a wide content
  container. A scoped container now stacks the form at narrow desktop widths.
- Template and Careers-pattern spacing referenced optional theme preset names
  without fallback values. Defaults now remain spaced when those names are
  absent. Single-job headings and layout widths are easier to customize.

## Limits and remaining manual release checks

Classic native archives retain theme post-list markup and metadata; the
Careers page supplies complete search/filter discovery. The suite does not
certify Elementor-authored pages, Elementor Pro templates, theme starter kits,
or arbitrary user overrides. New pattern classes apply to newly inserted
patterns; previously saved markup needs adaptation or reinsertion.

Keyboard focus, 200%/320% zoom, forced colors, actual screen-reader speech,
closed-job presentation, and user template overrides still require a final
manual release pass. Selected computed text contrast checks are not a complete
accessibility audit. See ../../THEME-SUPPORT.md and ../../TESTING.md.

## Other validation

- 313 WordPress smoke checks and review regressions passed.
- PHPCS passed for the changed block renderer, three templates, and four patterns.
- PHP syntax checks passed for the renderer and templates.
- The theme-suite JavaScript syntax check passed.
- Design lint passed with no errors or warnings.
- `git diff --check` passed.

- All four critical hiring workflows passed on a fresh-fixture rerun. The first
  run passed setup, candidate submission, and recruiter review, but employer
  preview returned a required-field error. The failed fixture was absent when
  inspected afterward, so the initial failure is not fully diagnosed; no
  production submission logic or existing workflow test was changed to hide it.
