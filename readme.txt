=== Intercessor ===
Contributors:      shepherd365
Tags:              prayer, prayer request, church, ministry, community
Requires at least: 6.3
Tested up to:      7.1
Requires PHP:      8.0
Stable tag:        1.1.1
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Effective prayer request management on WordPress.

== Description ==

The best free prayer request plugin for WordPress. Collect, manage, and organize prayer requests with moderation, privacy controls, reports, exports, a prayer wall, and prayer history.

**See It In Action**
Intercessor has been actively used on our website for several years, serving as a trusted platform for prayer requests and community prayer support. You can explore the live version, submit a prayer request, or pray for others through our online prayer wall here: [Submit Prayer](https://www.waymakerministry.org/prayer-request/)

**Submission**

* **Prayer Form block** — visitors submit requests directly from any page or post, with no coding required.
* **Anonymous submissions** — requesters can share publicly while hiding their name.
* **Private requests** — marked private and visible only to administrators and prayer managers.
* **Login gate** — optionally require a WordPress account before submitting.
* **Auto-registration** — guests can be automatically registered as WordPress users with a `requester` role on submission.
* **Google reCAPTCHA** — v2 checkbox or v3 invisible score-based spam protection.
* **Rate limiting** — configurable per-email daily submission cap.
* **Profanity filter** — flags rather than blocks requests, so moderators decide.
* **Terms and privacy acceptance** — optional checkbox with configurable label and URL.

**Moderation**

* **Full workflow** — approve, reject, mark private, archive, and restore individual requests.
* **Bulk actions** — process multiple requests at once from the list table.
* **Moderator notes** — private internal annotations on each prayer request, never shown publicly.
* **Status audit trail** — immutable history log for every status change, including actor and timestamp.

**Requester Management**

* **Requester database** — every submitter is tracked as a deduplicated requester record.
* **WordPress user linking** — optional link between a requester record and a WP user account.
* **Tabbed requester detail view** — five-tab page covering profile, prayer requests, status history, notes, and delete.
* **Requester notes** — private admin notes attached directly to a requester record, separate from prayer notes.

**Display**

* **Prayer Wall block** — displays approved requests with pagination and a live "I prayed for this" counter.
* **Prayer History block** — shows the full status timeline for a single request.

**Notifications**

* **Admin email** — notified on every new submission.
* **Requester email** — notified when their request is received and when its status changes.
* **Scheduled prayer reports** — configurable cron job sends periodic prayer activity digests.

**Roles and capabilities**

* **Three custom roles** — `prayer_manager` (full management access), `prayer_warrior` (read and export), `requester` (minimal WP access for auto-registered submitters).
* **Six custom capabilities** — `edit_prayers`, `manage_prayer_settings`, `view_prayer_reports`, `export_prayer_reports`, `view_prayer_sensitive_data`, `read_private_prayers`.

**Data and Exports**

* **CSV exports** — prayer requests, requesters, prayed counts, and plugin settings.
* **REST API** — 9 endpoints covering requests, requesters, history, and notes.
* **Six database tables** — all data stored locally; nothing sent to external services except reCAPTCHA.
* **No external dependencies** — BerlinDB is bundled; no Composer required on the server.

== Installation ==

1. Upload the `intercessor` folder to `/wp-content/plugins/`.
2. Activate the plugin from the **Plugins** screen in WordPress.
3. Six database tables are created automatically on activation.
4. Go to **Intercessor → Settings** to configure approval rules, notifications, reCAPTCHA, and roles.
5. Add the **Prayer Form** block to any page to start accepting prayer requests.

== Frequently Asked Questions ==

= Do I need to run a build step for the Gutenberg blocks? =

No. The editor scripts are pre-compiled and included in the plugin. Simply activate and the blocks are ready to use.

= Where are prayer requests stored? =

All data is stored in your WordPress database in six dedicated tables prefixed with `{prefix}intercessor_`. No data is sent to external services except Google reCAPTCHA verification (when enabled).

= Can visitors submit prayer requests without logging in? =

Yes, by default. You can require login under **Settings → General → Require Login to Submit**. You can also enable auto-registration so guest submitters receive a WordPress account with the `requester` role.

= What is the difference between anonymous and private requests? =

Anonymous means the request is displayed publicly but the requester's name is hidden. Private means the request is not displayed publicly at all — it is only visible to administrators and prayer managers.

= How does the profanity filter work? =

Requests containing words from your prohibited word list are not blocked. They are submitted normally but forced to "Pending" status and flagged with a moderator note identifying which terms were matched. The moderator then decides whether to approve or reject.

= What are requester notes? =

Requester notes are private admin annotations attached directly to a requester record, separate from prayer request notes. They appear on the Notes tab of the requester detail page and are never shown publicly.

= What is the difference between prayer_manager and prayer_warrior roles? =

A `prayer_manager` has full access: they can moderate requests, manage settings, view reports, and export data. A `prayer_warrior` has read-only access: they can view reports and export data but cannot modify settings or moderate requests.

= How does the REST API handle authentication? =

Public read endpoints (listing approved requests, viewing history) are open. All write and moderation endpoints require the `edit_prayers` capability. Export and report endpoints require `export_prayer_reports`.

= Can I export all data before uninstalling? =

Yes. Use **Intercessor → Tools** to download CSV exports of all data before removing the plugin. Enable **Delete All Data on Uninstall** in **Settings → Advanced** if you want the tables dropped on removal.

== Screenshots ==

1.	Prayer Form block on the front end.
2.	Prayer Wall block with "I prayed for this" counters.
3.	Dashboard with an overview of prayer activity and requests awaiting moderation.
4.	Admin prayer requests list with status filters, search, and bulk actions.
5.	Add Prayer Request screen for recording a request on behalf of another person.
6.	Prayer History login.
7.	Requester detail page with profile and stats.
8.	Settings: General tab with submission and approval rules.
9.	Prayer Reports.
10.	Import / Export tools.

== Source Code & Development ==

The full source code for Intercessor is publicly available on GitHub:
[https://github.com/victoraigbeghian/intercessor](https://github.com/victoraigbeghian/intercessor)

The Gutenberg block editor scripts in `assets/js/blocks/` are built from their unminified source files in `src/blocks/` (also included in this plugin) using webpack. To rebuild the blocks from source:

1. Clone the repository or extract the plugin.
2. Run `npm install` to install build dependencies.
3. Run `npm run build` to compile the block scripts.

Other JavaScript files in `assets/js/public/` and `assets/js/admin/` are hand-written and shipped unminified.

== Changelog ==

= 1.1.1 =
* Redesigned the Prayer Requests page for prayer warriors: a read-only list of approved and private requests showing the full message (messages over 100 words collapse behind a "Read full request" dropdown), with "I prayed for this" as the only action.
* Prayer warriors no longer see requester email addresses, the names of anonymous requesters, moderation controls, or links to the request and requester detail screens.
* Prayer warriors can now record prayers from the admin list.
* Narrowed the Prayer Warrior role: it no longer has edit_prayers, report viewing or CSV export. Warriors use the new read-only Prayer Requests list instead.
* Added Screen Options (items per page and column toggles) to the Prayer Requests and Requesters admin lists.
* Added "I prayed for this" to the admin Prayer Requests list and request detail view, including private requests; the prayed-for notification email now covers private requests too.
* Added a content preview beneath each subject in the admin Prayer Requests list, and status buttons that now match each request's current status.
* Added contextual Help tabs to the Prayer Requests page, with separate content for prayer managers and prayer warriors.
* Enforced the read_private_prayers capability (previously defined but unused).
* Added an upgrade routine that re-syncs roles and capabilities on in-place updates; no deactivate/reactivate is needed.
* Fixed a duplicate "Prayer Requests" menu entry for users who can both moderate and read private requests.
* Fixed Admin_Loader::intercessor_is_admin_page() returning an undefined variable, and get_admin_current_screen() calling a method that does not exist.

= 1.1.0 =
* Added single prayer request detail page in the admin — moderators can view all request details, add private or public notes, and see the live prayer count in one place.
* Added prayer-level notes with optional private flag — notes are visible only to administrators when marked private.
* Added anonymous prayer recording — visitors who are not logged in can now use the "I prayed for this" button on the Prayer Wall; interactions are tracked by a session fingerprint and counted toward the prayer total.
* Improved "I prayed for this" tracking — repeat interactions by the same user or visitor increment a running count rather than inserting duplicate rows.
* Notification cron job now reschedules automatically when the frequency or send time is changed in Settings, without requiring plugin reactivation.
* Internal: centralised settings schema into a dedicated Registry class for consistency across Renderer, Sanitizer, and Settings Exporter.
* Fixed missing class imports in Admin_Loader that would have caused fatal errors when moderating, performing bulk actions, or managing prayer notes.
* Admins can now add prayer request from the prayer list page for themselves or for others.

= 1.0.1 =
* Added "Keep my prayer request private" option on the submission form. Private requests are visible only to administrators and never appear on the Prayer Wall.
* Added "Prevent Duplicate Requests" setting to block resubmission of the same subject line by the same requester.
* Added status filter bar (All / Pending / Approved / Rejected / Archived / Private) to the admin Prayer Requests list.
* Fixed "Settings saved." notice not appearing after saving settings.
* Fixed double blank lines throughout PHP source files.
* Redesigned the Prayer History block — guest login prompt is now a styled card with icon; authenticated view replaced the data table with a card-per-request layout showing status, date, prayer count, and inline edit/delete actions.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.1 =
Prayer Warriors now have read-only access to approved and private Prayer Requests, with no requester contact details. They can no longer moderate, report, or export requests. Capabilities re-sync on update. Adds Screen Options and contextual Help.

== External Services ==

This plugin optionally integrates with Google reCAPTCHA to protect the prayer request submission form from spam and automated submissions. This integration is entirely optional and disabled by default. It can be enabled and configured under Settings → reCAPTCHA.

**What data is sent and when:**
When reCAPTCHA is enabled, the visitor's browser loads the reCAPTCHA script from Google's servers when the prayer form page is viewed. On form submission, a reCAPTCHA token is sent from the visitor's browser to Google's verification API (`https://www.google.com/recaptcha/api/siteverify`) to validate the submission. No prayer request data is sent to Google — only the reCAPTCHA response token and your site's secret key.

**Service provider:**
Google reCAPTCHA is provided by Google LLC.
- Terms of Service: https://policies.google.com/terms
- Privacy Policy: https://policies.google.com/privacy
- reCAPTCHA Terms: https://cloud.google.com/recaptcha/docs/faq

If reCAPTCHA is not configured or disabled in the plugin settings, no connection to Google's servers is made.
