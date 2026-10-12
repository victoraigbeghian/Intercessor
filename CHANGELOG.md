# Changelog
## v1.1.1 (2026)

* Redesigned the Prayer Requests page for prayer warriors: a read-only list of approved and private requests showing the full message (messages over 100 words collapse behind a "Read full request" dropdown), with "I prayed for this" as the only action.
* Prayer warriors no longer see requester email addresses, the names of anonymous requesters, moderation controls, or links to the request and requester detail screens.
* Prayer warriors can now record prayers from the admin list.
* Narrowed the Prayer Warrior role: it no longer has `edit_prayers`, report viewing or CSV export. Warriors use the new read-only Prayer Requests list instead.
* Added Screen Options (items per page and column toggles) to the Prayer Requests and Requesters admin lists.
* Added "I prayed for this" to the admin Prayer Requests list and request detail view, including private requests; the prayed-for notification email now covers private requests too.
* Added a content preview beneath each subject in the admin Prayer Requests list, and status buttons that now match each request's current status.
* Added contextual Help tabs to the Prayer Requests page, with separate content for prayer managers and prayer warriors.
* Enforced the `read_private_prayers` capability (previously defined but unused).
* Added an upgrade routine that re-syncs roles and capabilities on in-place updates; no deactivate/reactivate is needed.

## v1.1.0 (2026)

* Added single prayer request detail page in admin with notes panel and live prayer count.
* Added prayer-level notes with optional private flag.
* Added anonymous prayer recording — non-logged-in visitors can now use the Prayer Wall button.
* Improved prayed-count tracking — repeat interactions increment a counter rather than inserting duplicate rows.
* Notification cron job reschedules automatically on settings change.
* Internal: centralised settings schema into a Registry class.
* Fixed missing class imports in Admin_Loader causing fatal errors on moderation and note actions.

## v1.0.1 (2026)

* Added private prayer request option on the submission form.
* Added duplicate request prevention (blocks resubmission of the same subject by the same requester).
* Added status filter bar to the admin Prayer Requests list (All / Pending / Approved / Rejected / Archived / Private).
* Fixed "Settings saved." notice not appearing after saving settings.
* Fixed double blank lines throughout PHP source files.
* Redesigned the Prayer History block — guest login prompt is now a styled card with icon; authenticated view uses a card-per-request layout.

## v1.0.0 (2026)

* Initial release.
