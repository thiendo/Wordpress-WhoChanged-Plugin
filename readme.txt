=== WhoChanged ===
Contributors: douple
Plugin URI: https://douple.net/whochanged/
Tags: activity log, audit log, admin log, change tracking, security
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know exactly who changed what in your WordPress admin — options, Customizer, plugins, users, menus, content and more.

== Description ==

**Plugin URL:** [https://douple.net/whochanged/](https://douple.net/whochanged/)
**Get WhoChanged PRO:** [https://checkout.freemius.com/plugin/35452/plan/58744/](https://checkout.freemius.com/plugin/35452/plan/58744/)

**WhoChanged** is a lightweight activity log for WordPress that quietly watches the admin area and records every meaningful change: who did it, what changed, and when. When something breaks after an update or a client swears "I didn't touch anything," WhoChanged gives you the answer in seconds instead of hours.

= What gets tracked =

* **Settings & options** — before/after values for any option updated through `wp-admin`.
* **Customizer** — every time a user saves changes in the Customizer.
* **Plugins** — activation, deactivation, deletion and bulk updates.
* **Users** — logins, failed login attempts, logouts and role changes.
* **Content** — posts and comments moved to trash, restored, or permanently deleted.
* **Navigation menus** — menu creation, updates and deletions.
* **WooCommerce** — order deletions (when WooCommerce is active).

= Why WhoChanged =

* **Clear before/after diffs.** Don't just see *that* something changed — see exactly what the old and new values were.
* **Built for teams.** Multiple admins and editors? Know exactly who is responsible for each change.
* **Fast, filterable log.** Search and filter by user, event type and date range directly from the WordPress admin.
* **CSV export.** Download your activity log as CSV whenever you need it.
* **Privacy-friendly by default.** Data stays in your own database — nothing is sent to third parties.
* **30-day activity history**, included on every install, no setup required.

= PRO features =

The Free plan tells you *what changed*. PRO helps you act on it, prove it, and get notified the moment it happens:

* **Unlimited retention** — the Free plan keeps 30 days of history; PRO lets you keep logs for 60/90 days or forever, so nothing ages out before an audit.
* **Excel (XLS) & PDF reports** — polished, client- and auditor-ready exports beyond the Free plan's CSV, including a one-click PDF export of the Statistics dashboard.
* **Instant email alerts** — get notified the moment specific event types happen (theme switches, plugin installs, admin role changes).
* **Role-based access control** — choose exactly which roles can view the activity log, and which roles are tracked in the first place. On Free, only Administrators can view logs and every user is tracked.
* **One-click purge** — wipe the entire activity log whenever you need a clean slate.

Free is genuinely useful on its own — full event coverage, filtering, diffs and CSV export, forever. PRO is for teams and agencies that need longer history, professional reports, real-time alerts and fine-grained access control.

= Support =

Found a bug or have a feature request? Please reach out through the plugin's support page. We read every report.

== Installation ==

1. Upload the `whochanged` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to the **WhoChanged** menu in your admin sidebar to view the activity log.
4. (Optional) Visit **WhoChanged → Settings** to review your 30-day Free retention window, or upgrade to PRO to configure longer retention, email alerts and role-based access.
5. Full documentation: [https://douple.net/whochanged/docs.html](https://douple.net/whochanged/docs.html)

== Frequently Asked Questions ==

= Does WhoChanged slow down my site? =

No. WhoChanged only runs in the WordPress admin area (`wp-admin`) and hooks into existing WordPress actions/filters, so it has no impact on your public-facing site's performance.

= Where is the activity log stored? =

In a dedicated table in your own WordPress database. No data is sent to any external service unless you explicitly configure email alerts (PRO), which use your site's own mail delivery.

= What happens to my data if I deactivate or uninstall the plugin? =

Deactivating the plugin keeps all logged data intact so nothing is lost if you reactivate later. Deleting the plugin from the Plugins screen keeps your data by default too — you can opt in to a full data wipe on uninstall from **WhoChanged → Settings → Danger Zone**.

= Is WhoChanged compatible with multisite? =

WhoChanged is designed for single-site installs. Multisite support is on the roadmap.

= How do I upgrade to PRO? =

1. Purchase WhoChanged PRO at [https://douple.net/whochanged/#pricing](https://douple.net/whochanged/#pricing) (or from **WhoChanged → Pricing / Upgrade** in wp-admin).
2. Complete Freemius checkout. Your license key is emailed to the address you used at payment.
3. In wp-admin go to **WhoChanged → Account**, click **Activate License**, and paste the key.
4. Confirm **WhoChanged → Settings** shows **PRO Active**.

If you checkout from inside the same site's wp-admin, Freemius often activates the license automatically. Full guide: [https://douple.net/whochanged/docs.html](https://douple.net/whochanged/docs.html)

= I bought PRO but the plugin still shows Free =

Open **WhoChanged → Account** and activate the license key from your Freemius purchase email. If the plan's site limit is full, deactivate the license on an unused site first, then activate on this one.

== Screenshots ==

1. Activity log listing with filters and before/after diffs.
2. Settings screen with retention, email alerts and role-based access controls (PRO).

== Changelog ==

= 1.1.1 =
* Housekeeping: PRO-only export (XLS/PDF) and bulk-purge implementations now live in a separate module that is excluded from this Free package entirely, instead of being shipped-but-locked. No behavior change for Free users.

= 1.1.0 =
* New: Freemius-based licensing for PRO plan management, upgrades and renewals.
* New: Clean uninstall routine with an opt-in "delete all data" setting.
* New: Deactivation routine that clears scheduled cron events.
* Removed: The legacy self-hosted update checker. Updates are now delivered through WordPress.org (free plan) and the Freemius SDK (PRO plan), as required for plugins listed on WordPress.org.
* Improved: Full WordPress Coding Standards (WPCS) compliance across the codebase.
* Improved: Internationalization — all strings are translation-ready with proper escaping and translator comments.
* Improved: Clearer visual distinction between Free and PRO features in the Settings screen — PRO-only fields are now disabled (not just labeled) on the Free plan.
* Changed: Free plan activity logs now auto-expire after 30 days (fixed); PRO unlocks configurable/unlimited retention.
* Changed: Excel (XLS) and PDF export moved to PRO; CSV export remains available on every plan.
* Security: Fixed a CSV export formula-injection issue and updated the bundled dompdf library to patch known vulnerabilities.
* Fixed: Activity timestamps could be displayed several hours off on sites not running in UTC; corrected across the log list, admin bar and all export formats.
* Fixed: Settings saved with an unchanged value (e.g. re-saving the Reading Settings screen) could log a misleading "changed" entry showing the same before/after value; before/after comparisons are now type-tolerant like WordPress core's own `update_option()` check.
* Fixed: Customizer saves logged every submitted setting as changed, even ones the admin didn't touch; the log now only shows settings whose value actually changed.
* Improved: Plugin activated/deactivated/installed/deleted log entries now use consistent wording with the rest of the activity log.
* Improved: Login/logout log entries no longer repeat the username shown in the User column; the IP address is shown instead.
* Improved: "Page on front" / "Page for posts" changes now show the page title instead of a raw page ID.
* Changed: "Export Statistics PDF" on the Statistics page moved to PRO, matching the XLS/PDF export gating on the main activity log.
* Improved: Freemius Account/Pricing screens now use the site's WP Admin color scheme for the Upgrade button instead of a fixed blue.
* Improved: Statistics dashboard redesigned — doughnut charts now show exact counts and percentages (not just a bare legend), plus a new "Activity by hour of day" chart, a period-over-period trend indicator on Total items, and a plain-language insight line (busiest day, top action share, peak hour).

= 1.0.1 =
* Maintenance release with minor fixes.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Adds Freemius-based licensing, a cleaner uninstall/deactivation flow, and full WordPress coding-standards + i18n compliance. Recommended update for all users.
