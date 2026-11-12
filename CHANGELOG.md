# Changelog

## v1.1.3 (2026)

### Security
* `GET /intercessor/v1/requests/{id}` and `GET /intercessor/v1/requests/{id}/history` were returning pending, rejected and archived prayer requests to unauthenticated visitors. Callers without the `edit_prayers` capability now receive only approved, public requests; anything else answers 404. Serves the same rule to both endpoints via a shared `can_view_request()` helper. (`Rest_Api`)
* The public history response no longer leaks the moderator's WordPress user ID. The previous `unset()` named a `moderator_id` key that does not exist, so `actor_user_id` was exposed; public responses are now built from the `PUBLIC_HISTORY_FIELDS` allow-list. (`Rest_Api`)
* Accounts created through the prayer form can no longer log in until the email address is confirmed. `Registration_Handler` hooks `wp_authenticate_user` and refuses a pending account — the password is verified first so the message is only shown to someone who knows it. Completing a password reset also confirms the address, so an expired confirmation link is not a lock-out. On upgrade from before 1.1.3, existing sessions of pending accounts are ended. (`Registration_Handler`, `Upgrader`)
* Logged-in users can no longer submit a prayer request under a different email address. `Submission_Pipeline::run()` substitutes the account email; moderators (`edit_prayers`) are exempt so they can still file a request on someone else's behalf over REST. (`Submission_Pipeline`)
* Added an hourly per-IP submission limit. The per-email limit alone could be bypassed by changing the address on each request, which left the moderation queue and admin inbox open to a script. Default 20 per hour, filterable with `intercessor_max_requests_per_ip_per_hour`; setting "Max Requests Per Email / Day" to 0 disables both limits. REMOTE_ADDR only — forwarded-for headers are not consulted. (`Rate_Limiter`, `Submission_Pipeline`)
* CSV exports now neutralise cells that a spreadsheet would run as a formula (strings starting with `=`, `+`, `-`, `@`, tab or carriage return get an apostrophe prefix). (`Abstract_Exporter::neutralise_formula()`)

### Fixed
* Bulk status changes were still emailing requesters whose request was already in the target status. The 1.1.2 fix had no effect because `Query::update_item()` reports a no-op write as a successful update; `bulk_update_status()` now reads each row and skips it when the status would not change, so no email and no history entry is written for unchanged requests. (`Prayer_Request_Query::bulk_update_status()`, `Bulk_Action_Handler`)
* The single-request status form was emailing the requester when the same status was saved again (for example to record a moderator note). `Moderation_Handler` now compares the current status before notifying. (`Moderation_Handler`)
* "Prevent Duplicate Requests" was comparing every submitter against the first requester in the database. `has_duplicate_subject()` passed a `fields` argument the query class does not implement and cast the returned row object to int, which always produced 1. Fixed by reading the ID off the row. (`Requester_Query::has_duplicate_subject()`)
* Notes added through the admin were always saved as private. Both handlers read the "Private (admin only)" checkbox with a default of `true`, but a browser does not submit an unticked checkbox, so the default was always returned. Both handlers now test for the key's presence. (`Note_Handler::handle_add()`, `Requester_Note_Handler::handle_add()`)
* Accounts created through the prayer form were never linked to their requester record. The Prayer History block, which looks requesters up by `wp_user_id`, always showed an empty history for these users, and the admin "resend confirmation" / "confirm manually" actions never appeared for them. New accounts are now linked when created; an existing guest row is linked when its owner submits while logged in with that email; and existing rows are repaired once on upgrade. (`Requester_Query::link_to_user()`, `Registration_Handler`, `Upgrader`)
* Dates and times were shown in UTC on the Prayer Wall, Prayer History and admin screens, and report periods (`today`, `this week`, `this month`) and the daily and weekly charts bucketed stored UTC values as if they were local calendar days. Storage stays UTC; conversion happens at the edges through a new `Local_Time` helper (`format()`, `to_utc()`, `utc_offset_seconds()`), and `Date::period_boundaries_utc()` gives report WHERE clauses boundaries in the same zone as the column. Daily and weekly grouping shifts the column by the site's current UTC offset before `DATE()`, `WEEK()` and `YEAR()`. (`Util\Local_Time`, `Database\Queries\Date`, `Prayer_Request_Stats`)
* Editing your own private prayer request was turning it into a pending request. Private requests now stay private; other requests return to pending through `update_status()` so the change is written to the history; edited text runs through the profanity filter. The JSON response reports the resulting status so the Prayer History block badge updates correctly. (`Public_Loader::handle_update_own_request()`)
* `Rest_Api::update_status()` did not notify the requester — the admin screens did, which meant the same moderation action sent an email or not depending on which path was used. The endpoint now calls `Notifier::notify_status_change()` when the status actually changed, and returns 404 instead of 500 for an unknown request. (`Rest_Api::update_status()`)
* Unchecking a checkbox on the Settings screen did not save — the box reappeared ticked on reload. The sanitizer dropped missing keys so that `Renderer::sanitize` could merge them with the stored settings, which let a previously stored `'1'` survive. Checkbox and multicheck fields now default to `'0'` / `[]` when absent from the submitted form. Reported by @nicolvvw on wordpress.org for Show Site Terms, Show Privacy Policy, and Require Acceptance of Terms. (`Admin\Settings\Sanitizer`)
* Marking a prayer request `private` from the admin left `is_public = 1` on the row, so the data contradicted the status. Moves into `private` now clear `is_public`; moves out restore it. (`Prayer_Request_Query::update_status()`)
* `uninstall.php` was deleting options named `db_version_intercessor_<table>`, but the table layer stores them as `intercessor_<table>_db_version`. The six options were never removed on uninstall. (`uninstall.php`)

### Added
* `Request::get_signed_int()` was already shipped in 1.1.2. This release adds `Util\Local_Time` (UTC↔site-timezone conversion and offset helpers) and `Util\Rate_Limiter::is_client_allowed()` / `record_client_submission()` for per-client limiting. (`Util\Local_Time`, `Util\Rate_Limiter`)
* `Database\Queries\Date::period_boundaries_utc()` — UTC counterpart of `period_boundaries()`, for WHERE clauses against UTC date columns.
* Unit and integration test coverage for the behaviour changed in this release: `tests/Unit/Tools/Abstract_ExporterTest.php`, `tests/Integration/Http/Rest_ApiTest.php`, `tests/Integration/Util/Registration_HandlerTest.php`, plus new cases in `Rate_LimiterTest`, `Requester_QueryTest` and `Prayer_Request_QueryTest`.
* `composer.json` for development and test tooling, plus `.github/workflows/tests.yml` running `php -l` on every file for PHP 8.0–8.4 and the unit suite on 8.2–8.4.

### Changed
* The custom-table data layer is now described accurately as a BerlinDB-style implementation written for Intercessor, not as bundled BerlinDB. File headers under `src/includes/berlindb/`, `readme.txt`, `README.md`, `wiki/Architecture.md` and `wiki/Database-Schema.md` updated; the Architecture page lists the differences from upstream BerlinDB that matter when writing queries. The namespace, directory and translatable "Bundled BerlinDB library" error string are left as they are.
* The `.gitignore` now excludes `/dist` and `/phpunit.xml`; the packaging script excludes `vendor`. The committed `dist/intercessor-1.1.1.zip` is no longer tracked.

### Removed
* The stale `phpunit.xml` (left over from another project, pointing at `tests/bootstrap.php` and `src/` paths that do not exist here) that was overriding `phpunit.xml.dist`.

---

## v1.1.2 (2026)

### Fixed
* Bulk status-change action was sending notification emails to all selected requesters even when their request was already in the target status. Notifications now go only to requesters whose status was actually changed. (`Bulk_Action_Handler`, `Prayer_Request_Query::bulk_update_status()`)
* `Request::input()` was returning an empty string instead of the caller's `$default` when a POST or GET key was present but submitted with a blank value. Because all input is pre-sanitized to strings in the constructor, the previous null-coalescing logic never reached `$default` for blank fields.
* `Rest_Api::create_request()` was allocating a redundant `Request` object solely to read the visitor's IP address for reCAPTCHA verification. `REMOTE_ADDR` is now read directly from `$_SERVER`.
* The `intercessor-prayer-form` script was registered with a hardcoded date suffix appended to the version string (`INTERCESSOR_VERSION . '-20260514-form'`), making its browser cache key inconsistent with every other plugin asset. Corrected to use `INTERCESSOR_VERSION` alone.

### Added
* `Request::get_signed_int()` — a signed-integer accessor using `intval()` for input fields that may legitimately carry a negative value. `get_int()` continues to use `absint()` for primary keys and page numbers; its docblock now explicitly states the unsigned-only behaviour.

### Changed
* Docblocks in `Public_Loader::handle_form_submission()` and `Rest_Api::create_request()` updated to reference `Submission_Pipeline::run()` — the actual delegation target since 1.0.1 — and to include the duplicate-subject check in the list of pipeline steps performed.
* Inline comment in `Upgrader` corrected to remove a forward reference to the then-unreleased version `1.1.2`.

### Removed
* `src/Http/Submission_Service.php` — dead class with zero call sites. Both `Public_Loader` and `Rest_Api` have always called `Submission_Pipeline::run()` directly. `Submission_Service` was also missing the duplicate-subject check, making it a silent regression risk if ever wired in.
* `src/Loader.php` — legacy bootstrap singleton superseded by `Plugin.php` in 1.1.0 and no longer referenced anywhere. `Loader` also lacked the `Upgrader::maybe_upgrade()` call introduced in 1.1.1, so accidentally restoring it would have silently broken capability re-sync on in-place upgrades.

---

## v1.1.1 (2026)

### Added
* Redesigned Prayer Requests page for prayer warriors: a read-only list of approved and private requests showing the full message (messages over 100 words collapse behind a "Read full request" dropdown), with "I prayed for this" as the only action.
* Prayer warriors can now record prayers from the admin list.
* Screen Options (items per page and column toggles) on the Prayer Requests and Requesters admin lists.
* "I prayed for this" on the admin Prayer Requests list and request detail view, including private requests; the prayed-for notification email now covers private requests too.
* Content preview beneath each subject in the admin Prayer Requests list, and status buttons that match each request's current status.
* Contextual Help tabs on the Prayer Requests page, with separate content for prayer managers and prayer warriors.
* Upgrade routine that re-syncs roles and capabilities on in-place updates — no deactivate/reactivate needed.

### Changed
* Narrowed the Prayer Warrior role: it no longer has `edit_prayers`, report viewing, or CSV export. Warriors use the new read-only Prayer Requests list instead.
* Prayer warriors no longer see requester email addresses, the names of anonymous requesters, moderation controls, or links to the request and requester detail screens.
* Enforced the `read_private_prayers` capability (previously defined but unused).

### Fixed
* Duplicate "Prayer Requests" menu entry appearing for users who can both moderate and read private requests.
* `Admin_Loader::intercessor_is_admin_page()` returning an undefined variable.
* `Admin_Loader::get_admin_current_screen()` calling a method that does not exist.

---

## v1.1.0 (2026)

### Added
* Single prayer request detail page in admin with notes panel and live prayer count.
* Prayer-level notes with optional private flag — notes are visible only to administrators when marked private.
* Anonymous prayer recording — non-logged-in visitors can now use the Prayer Wall "I prayed for this" button.
* Admins can now add a prayer request from the prayer list page on behalf of themselves or others.

### Changed
* Prayed-count tracking — repeat interactions by the same user or visitor increment a running counter rather than inserting duplicate rows.
* Notification cron job reschedules automatically when the frequency or send time is changed in Settings, without requiring plugin reactivation.
* Internal: centralised settings schema into a dedicated Registry class for consistency across Renderer, Sanitizer, and Settings Exporter.

### Fixed
* Missing class imports in `Admin_Loader` that caused fatal errors when moderating, performing bulk actions, or managing prayer notes.

---

## v1.0.1 (2026)

### Added
* Private prayer request option on the submission form — private requests are visible only to administrators and never appear on the Prayer Wall.
* Duplicate request prevention — blocks resubmission of the same subject line by the same requester.
* Status filter bar on the admin Prayer Requests list (All / Pending / Approved / Rejected / Archived / Private).

### Changed
* Redesigned the Prayer History block — guest login prompt is now a styled card with icon; authenticated view uses a card-per-request layout showing status, date, prayer count, and inline edit/delete actions.

### Fixed
* "Settings saved." notice not appearing after saving settings.
* Double blank lines throughout PHP source files.

---

## v1.0.0 (2026)

* Initial release.
