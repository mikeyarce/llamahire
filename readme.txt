=== LlamaHire – Job Board & Careers ===
Contributors: llamahire
Tags: jobs, careers, hiring, applications, job board
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modern hiring for WordPress.

== Description ==

LlamaHire provides an end-to-end careers workflow with beautiful block-first job listings, built-in applications, a focused hiring dashboard, and Google for Jobs-compatible structured data.

The free plugin includes unlimited jobs, composable search and filters with shareable URLs, departments, workplace and employment details, salary ranges, featured roles, application deadlines, candidate and employer emails, private resume storage, application notes and statuses, and CSV export.

== Installation ==

1. Upload the `llamahire` folder to `/wp-content/plugins/` and activate LlamaHire.
2. Add your first role under Jobs.
3. Create a page and insert the “Careers page” pattern, or add the Jobs Directory block.
4. Published job pages automatically include their application form.

== Frequently Asked Questions ==

= Where are applications stored? =

Applications use a dedicated database table. Resume files are stored in a protected private directory and can only be downloaded by an authorized WordPress user through a signed admin URL.

On production sites, resume uploads fail closed if WordPress cannot create storage outside the public web root. Site Health reports the storage status so the host can correct it before accepting applications.

= Does this require WooCommerce? =

No. LlamaHire has no WooCommerce dependency.

== Changelog ==

= 0.1.0 =
* Initial product foundation with jobs, composable careers search and filters, applications, admin review, CSV export, and JobPosting schema.
