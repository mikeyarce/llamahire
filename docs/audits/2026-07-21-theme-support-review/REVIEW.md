# Theme and template support review

Date: July 21, 2026

## Outcome

Theme support is part of the Free MVP. LlamaHire now provides native block templates for single jobs, the jobs archive, and department archives on WordPress 6.7 and newer, while preserving WordPress's normal PHP template hierarchy for classic themes and older supported WordPress versions.

## Research findings

- [WP Job Manager](https://wpjobmanager.com/document/developer-reference/themes/template-overrides/) lets themes copy plugin PHP partials into a `job_manager/` directory. This is flexible, but copied files can become stale when plugin markup changes.
- [Simple Job Board](https://wordpress.org/plugins/simple-job-board/) similarly documents a `simple_job_board/` theme override directory and ships separate layout families.
- WordPress 6.7 introduced [`register_block_template()`](https://developer.wordpress.org/reference/functions/register_block_template/) for plugin-owned templates that remain editable in the Site Editor.
- WordPress's official plugin-template guidance recommends standard template-hierarchy slugs such as `single-{post_type}` and `archive-{post_type}`. Themes and user customizations can override those registered defaults without plugin-specific copying machinery.
- The [block-theme template hierarchy](https://developer.wordpress.org/themes/templates/template-hierarchy/) already defines `single-{post_type}.html`, `archive-{post_type}.html`, and `taxonomy-{taxonomy}.html`, with user-created templates and child/theme templates taking priority.

## Decisions

1. Use the Core block-template registry on WordPress 6.7+ instead of creating a second LlamaHire-specific template override system.
2. Register the standard hierarchy slugs `single-llamahire_job`, `archive-llamahire_job`, and `taxonomy-llamahire_department`.
3. Use the active block theme's header and footer template parts, core layout blocks, spacing presets, and base/contrast colors.
4. Keep classic themes on the native `single-llamahire_job.php`, `archive-llamahire_job.php`, and `taxonomy-llamahire_department.php` hierarchy. Do not replace unknown theme wrappers with a generic plugin shell.
5. Render LlamaHire discovery blocks in job and department archives so closed, expired, and draft roles cannot leak through a generic post loop.
6. Keep component CSS low-specificity, inherit typography, derive surfaces/borders from theme tokens where available, and provide safe fallbacks plus reduced-motion and forced-colors behavior.

## Validation

- All 131 smoke checks pass, including conditional registry/content checks for the three templates.
- The complete six-test browser hiring workflow passes.
- Twenty Twenty-Five was visually checked at desktop and 390px on the Studio demo site for the jobs archive, department archive, and single-job/application views.
- Twenty Twenty-One was visually checked at 390px in the isolated environment for the classic-theme single-job/application fallback.
- The deterministic release ZIP includes the three runtime template assets.

## Remaining release evidence

- Repeat visual checks on the final supported theme set, including a dark block theme and a popular third-party classic theme.
- Complete RTL, localization, 320% zoom, forced-colors, VoiceOver, and NVDA evidence before the release candidate.
- Document theme-author examples in public developer documentation before 1.0.
