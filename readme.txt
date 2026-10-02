=== LlamaHire – Job Board & Careers ===
Contributors: mikeyarce
Tags: jobs, careers, hiring, applications, job board
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish jobs, build a careers page, and manage applications in WordPress.

== Description ==

LlamaHire brings job publishing, a careers page, and application management into WordPress. Use it for one company's hiring or for a multi-employer job board.

= Publish and discover jobs =

* Create jobs with departments, physical or remote locations, employment details, salary ranges, deadlines, and organization information.
* Build a careers page with WordPress blocks and patterns. Combine the job search, filters, directory, job cards, and featured jobs to fit your theme.
* Let visitors filter and share job results through URLs. Open jobs can appear in the job RSS feed and WordPress XML sitemaps.
* Show job facts and JobPosting structured data from the same saved fields. Closed and expired jobs remain available as historical pages without accepting new applications.

= Collect and review applications =

* Add an application form to published jobs or place the Application Form block on a page.
* Choose whether phone, resume, and cover letter fields are required, optional, or hidden. Optional Turnstile or reCAPTCHA challenges can be enabled by an administrator.
* Review applications in a searchable, filterable inbox; update statuses, add private notes, and export CSV files. Granular permissions limit who can view candidate data.
* Configure candidate and hiring-team emails, delivery previews, retention, and the privacy notice shown beside application forms. Resumes are stored privately and downloaded through an authorized endpoint.

= Run a multi-employer job board =

Job-board mode adds email-verified employer registration, optional operator approval, frontend job submission and editing, My Jobs and Employer Account pages, and an employer workspace for applications to their own jobs. Operators can configure active-listing limits and listing duration. Company careers mode keeps the simpler workflow for one organization.

== Installation ==

1. Install and activate LlamaHire from Plugins in WordPress.
2. Open Jobs > Setup and choose a company careers site or multi-employer job board. Set your organization details, hiring inbox, candidate privacy notice, retention period, and Careers page.
3. Add a job under Jobs, complete its details, and publish it. Jobs accepting on-site applications show the application form automatically.
4. Review incoming applications under Jobs > Applications. In job-board mode, review the employer registration and public submission settings before inviting employers.

== Frequently Asked Questions ==

= Where are applications stored? =

Applications use a dedicated database table. Resume files are stored in a private directory and can only be downloaded by an authorized WordPress user through a protected endpoint. The configured retention period controls scheduled deletion of older applications and resumes. LlamaHire also connects to WordPress's personal-data export and erasure tools.

By default, production resume uploads require writable storage outside the public web root. A host can explicitly configure a verified protected fallback; otherwise uploads fail closed. Site Health reports the storage status so the host can correct it before accepting applications.

= Can employers submit their own jobs? =

Yes. Choose multi-employer job-board mode in Setup. Employers verify their email address, then receive access automatically or after operator approval, according to the board's settings. They can create and manage their own listings and review applications to their own jobs. Site operators retain board-wide control.

= Does the plugin require WooCommerce or an external account? =

No. LlamaHire has no WooCommerce dependency, and its core job and application features do not require an external account. Optional geocoding and anti-spam providers require keys from their respective services.

= Will application emails reach my inbox? =

LlamaHire sends through WordPress's configured mail transport. Configure the hiring inbox in Setup or Settings and send a test email. Delivery depends on your site's mail configuration; Site Health reports related setup guidance.

== Source code and support ==

Plugin website: https://llamahire.com/

Source code and build instructions: https://github.com/mikeyarce/llamahire. The bundled admin JavaScript and CSS are built from the unminified `src/` files with `npm ci` and `npm run build`.

For setup help or bug reports, use the WordPress.org support forum for this plugin after publication, or open an issue at https://github.com/mikeyarce/llamahire/issues.

== External services ==

LlamaHire does not contact an external service before an administrator completes Setup or enables a service in Settings. Usage reporting is unchecked by default and requires the administrator to enable it.

= Optional usage reporting =

Usage reporting defaults to off in both Setup and Settings. An administrator must check the consent box to enable it. Saving Setup for later does not enable reporting. When enabled, LlamaHire sends a reporting-enabled event to PostHog, and another event if the plugin is reactivated. It also sends a site snapshot when reporting begins and every 15 days afterward. All events include a random installation ID, site URL, site mode, and LlamaHire, WordPress, and PHP versions. Snapshots also include aggregate counts of published, draft, and pending jobs; active plugin names and versions; active theme name and version; locale; and multisite status. No applicant, employer-account, visitor, individual job, or page-view data is sent. The administrator can disable reporting in Settings at any time. This stops future events; previously sent events remain subject to PostHog's retention policy.

Service: https://posthog.com/
Privacy policy: https://posthog.com/privacy
Event details: https://github.com/mikeyarce/llamahire/blob/main/docs/TELEMETRY.md

= Google Geocoding API =

Geocoding is disabled by default. When a site administrator adds a Google Maps Platform API key, LlamaHire queues the public street, locality, region, postal code, and country of physical and hybrid jobs for Google after that address is saved. Google returns coordinates that LlamaHire caches by address hash, stores with the job, and includes in its JobPosting structured data. Fully remote jobs are not sent. Provider requests run outside the save request, and failures use bounded retry with backoff, so an API error does not prevent or delay a job save.

Service: https://developers.google.com/maps/documentation/geocoding/
Privacy policy: https://policies.google.com/privacy
Terms: https://cloud.google.com/maps-platform/terms

= Cloudflare Turnstile =

When enabled, the public form loads the Turnstile script from Cloudflare and sends the resulting verification token, the configured secret key, and the form context to Cloudflare for validation. Cloudflare may also receive technical information such as the visitor's IP address and browser details when its widget loads.

Service: https://developers.cloudflare.com/turnstile/
Privacy policy: https://www.cloudflare.com/privacypolicy/
Terms: https://www.cloudflare.com/website-terms/

= Google reCAPTCHA =

When enabled, the public form loads the reCAPTCHA script from Google and sends the resulting verification token and the configured secret key to Google for validation. Google may also receive technical information such as the visitor's IP address and browser details when its widget loads.

Service: https://developers.google.com/recaptcha
Privacy policy: https://policies.google.com/privacy
Terms: https://policies.google.com/terms

== Changelog ==

= 0.1.0 =
* Initial release with company and multi-employer job-board modes, careers blocks and patterns, structured job details, applications, private resumes, employer self-service, recruiter workflows, privacy controls, and JobPosting structured data.
