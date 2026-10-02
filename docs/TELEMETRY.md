# Optional installation reporting

Usage reporting defaults to off in both Setup and saved settings. An administrator must check the box to enable it. Reporting begins only when an administrator completes Setup with the box checked, or later enables it in Settings. Saving Setup for later does not enable reporting; reopening it restores the draft checkbox choice. Once settings have been saved, Setup and Settings use the saved choice. Turning the choice off stops future events. Previously sent events remain in PostHog until removed under that project's retention policy.

The plugin sends server-side events to PostHog. It does not load PostHog JavaScript, autocapture, session replay, or feature flags. No applicant, employer-account, WordPress user, visitor, individual job, or application data is included.

## Events

| Event | Trigger | Question answered |
| --- | --- | --- |
| `llamahire_reporting_enabled` | An administrator enables reporting | How many consenting sites are reporting? |
| `llamahire_reactivated` | An opted-in site reactivates the plugin | How often is LlamaHire reactivated on consenting sites? |
| `llamahire_site_snapshot` | When reporting begins, then every 15 days while enabled | How many published, draft, and pending jobs do consenting sites have, and which platform configurations do they run? |

All events use a random per-site installation ID as PostHog's `distinct_id` and contain `site_url`, `site_mode`, `plugin_version`, `wp_version`, and `php_version`. The snapshot adds `jobs_published`, `jobs_draft`, `jobs_pending`, `active_plugins` (names and versions, including network-active plugins), `active_theme` (name and version), `locale`, and `is_multisite`. Job counts are aggregate post counts, not individual listings. A site URL and its plugin list may identify its owner; both are explicitly named in the consent text. The ID is generated only when an event is sent. A disabled and later re-enabled site keeps the same ID so it is not counted as a new site. Person-profile creation is disabled for these events.

`llamahire_reporting_enabled` is **not** a count of all plugin activations. The first activation happens before consent. WordPress.org's aggregate active-install statistics remain the better measure of total adoption.

## PostHog configuration

The PostHog project token and US ingestion host are configured in `llamahire.php`. The project token is a public ingestion token, not a personal API key. The code accepts only the US and EU PostHog ingestion hosts and rechecks the opt-in at delivery time. The first delivery runs through WP-Cron after a one-minute delay, outside the setup request. After each snapshot, the next is scheduled 15 days later. Snapshot counts and the active plugin inventory are collected only during delivery, not on normal page views. WordPress traffic must run WP-Cron (or a server cron must call it) for periodic delivery. Keep the event list and property allowlist narrow; any new data category needs a revised consent description and privacy review.

## Next questions

Do not add application counts or funnel events until there is a clear product question and a privacy-safe definition. Never send candidate or employer identity, notes, resumes, email errors, or page URLs beyond the consented site URL.
