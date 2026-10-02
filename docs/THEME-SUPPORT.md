# Launch theme support

LlamaHire supports public components inside block and classic themes. The
launch compatibility matrix is Twenty Twenty-Five (default and Midnight),
Astra, GeneratePress, and Hello Elementor (alone and with Elementor active).
Tested versions and results belong in the release validation record; this is
not a promise that every starter template or theme customization is covered.

## Integration behavior

- WordPress 6.7+ block themes receive editable single-job, jobs-archive, and
  department templates through the native template registry. Theme and user
  templates can override those defaults.
- Classic themes and older supported WordPress versions retain the native theme
  hierarchy and wrappers. Their archives may show the theme's ordinary post
  list, dates, and author metadata. The supplied Careers page provides the full
  searchable/filterable job directory across both theme types.
- Public components inherit fonts, use theme base/contrast presets when
  available, and supply scoped structural styles and fallback colors/spacing.
  The job/application layout stacks inside narrow content columns, including
  desktop sidebars. Site headers, footers, and navigation remain theme-owned.
- Patterns remain editable core blocks. New pattern groups include scoped
  small-screen width constraints; existing saved patterns retain their saved
  markup and do not acquire new classes automatically. Reinsert a pattern or
  adapt its saved groups to receive those structural changes.
- Hello with Elementor active is tested for compatibility with the normal
  WordPress content flow. Dedicated Elementor widgets, Elementor-authored
  Careers pages, and Elementor Pro Theme Builder templates are not certified
  by this matrix.

## Release gate

Run `npm run test:e2e:themes` against the disposable wp-env site. The six cases
check Careers, hero/featured and department patterns, native jobs archives,
single jobs/forms, registration, and authenticated employer pages at desktop
and mobile widths. They check a narrow job column, empty search results,
rendered form notices, duplicate forms, horizontal overflow, and selected
text contrast. Theme versions and screenshots are saved in the HTML report.
Notice screenshots test rendering; actual submissions belong to the hiring
workflow suite.

Review the report visually before release. Also check keyboard focus, zoom,
closed jobs, real sidebar configurations, and user template overrides. Passing
these checks establishes evidence for the tested configurations, not universal
accessibility certification. See [TESTING.md](TESTING.md) for commands.
