# Intercessor

> The most effective and complete WordPress plugin for managing prayer requests — with public submission, anonymous and private sharing, requester management, moderation workflows, exports, reports, and prayer activity tracking.

[![WordPress Plugin Version](https://img.shields.io/badge/version-1.1.1-blue)](https://github.com/victoraigbeghian/intercessor/releases)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Requires PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://php.net)
[![Requires WordPress](https://img.shields.io/badge/WordPress-6.3%2B-blue)](https://wordpress.org)

---

## Overview

Intercessor gives churches, ministries, and faith communities a complete workflow for collecting, moderating, and displaying prayer requests on their WordPress site. It ships with no external dependencies — BerlinDB is bundled and no Composer step is required on the server.

---

## Features

| Area | What's included |
|---|---|
| **Submission** | Prayer Form block, anonymous option, private requests, login gate, auto-registration, reCAPTCHA v2/v3, rate limiting, profanity filter, terms/privacy acceptance |
| **Moderation** | Approve / Reject / Archive individually or in bulk, status audit trail, internal moderator notes |
| **Requester management** | Deduplicated requester records, WP user linking, five-tab detail page, requester notes |
| **Display** | Prayer Wall block with live prayer counters, Prayer History timeline block |
| **Notifications** | Admin and requester emails on submission and status change, scheduled prayer report cron |
| **Roles** | `prayer_manager`, `prayer_warrior`, `requester` — six custom capabilities |
| **REST API** | 9 endpoints under `/wp-json/intercessor/v1/` |
| **Export** | CSV export for requests, requesters, prayed counts, and settings |
| **Database** | 6 custom tables via bundled BerlinDB ORM |

---

## Requirements

| Dependency | Minimum |
|---|---|
| PHP | 8.0 |
| WordPress | 6.3 |
| MySQL / MariaDB | 5.7 / 10.3 |
| Node.js *(dev only)* | 18+ |

---

## Installation

### From the WordPress Plugin Directory

1. Go to **Plugins → Add New** and search for **Intercessor**.
2. Click **Install Now**, then **Activate**.

### From this repository

```bash
cd wp-content/plugins
git clone https://github.com/victoraigbeghian/intercessor.git

# Build JS blocks (required for the Gutenberg editor UI)
cd intercessor
npm install
npm run build
```

Then activate the plugin from **Plugins → Installed Plugins**.

### From a release zip

Download the latest `.zip` from [Releases](https://github.com/victoraigbeghian/intercessor/releases), then upload via **Plugins → Add New → Upload Plugin**.

---

## Directory Structure

```
intercessor/
├── intercessor.php                    Bootstrap, constants, autoloader
├── uninstall.php                      Removes caps, roles, options, tables
├── readme.txt                         WordPress.org readme
├── README.md                          This file
├── package.json
├── phpcs.xml
├── phpunit.xml.dist
│
├── src/                               PHP source (PSR-4, namespace Intercessor\)
│   ├── Plugin.php                     Boot: registers all subsystems on init
│   ├── Activator.php                  Activation: tables, roles, caps, cron
│   ├── Deactivator.php                Deactivation: cron, rewrite flush
│   ├── Requirements.php               PHP/WP version gate
│   ├── Roles.php                      3 roles, 6 caps, constants, static methods
│   ├── Upgrader.php                   Re-syncs roles/caps when the plugin version changes
│   │
│   ├── Admin/
│   │   ├── Admin_Loader.php           Menus, assets, all admin_post hooks
│   │   ├── Bulk_Action_Handler.php    Bulk approve/reject/delete
│   │   ├── Display_Page.php           Settings API wiring
│   │   ├── Moderation_Handler.php     Single request status changes
│   │   ├── Note_Handler.php           Prayer note add/delete
│   │   ├── Prayer_Request_List_Table.php
│   │   ├── Requester_List_Table.php   List table with View row action
│   │   ├── Requester_Note_Handler.php Requester note add/delete
│   │   ├── Requester_View.php         Tabbed requester detail (5 tabs)
│   │   ├── Settings.php
│   │   └── Settings/
│   │       ├── Registry.php
│   │       ├── Renderer.php
│   │       ├── Repository.php
│   │       └── Sanitizer.php
│   │
│   ├── Block/
│   │   ├── Block_Loader.php
│   │   ├── Prayer_Form_Block.php
│   │   ├── Prayer_History_Block.php
│   │   └── Prayer_Wall_Block.php
│   │
│   ├── Database/
│   │   ├── Table_Registry.php         Registers & installs all 6 tables
│   │   ├── Query/
│   │   │   ├── Prayed_Count_Query.php
│   │   │   ├── Prayer_History_Query.php
│   │   │   ├── Prayer_Note_Query.php
│   │   │   ├── Prayer_Request_Query.php
│   │   │   ├── Requester_Note_Query.php
│   │   │   └── Requester_Query.php
│   │   ├── Row/
│   │   │   ├── Prayed_Count.php
│   │   │   ├── Prayer_History.php
│   │   │   ├── Prayer_Note.php
│   │   │   ├── Prayer_Request.php
│   │   │   ├── Requester.php
│   │   │   └── Requester_Note.php
│   │   ├── Schema/
│   │   │   ├── Prayed_Counts_Schema.php
│   │   │   ├── Prayer_History_Schema.php
│   │   │   ├── Prayer_Notes_Schema.php
│   │   │   ├── Prayer_Requests_Schema.php
│   │   │   ├── Requester_Notes_Schema.php
│   │   │   └── Requesters_Schema.php
│   │   └── Table/
│   │       ├── Prayed_Counts_Table.php
│   │       ├── Prayer_History_Table.php
│   │       ├── Prayer_Notes_Table.php
│   │       ├── Prayer_Requests_Table.php
│   │       ├── Requester_Notes_Table.php
│   │       └── Requesters_Table.php
│   │
│   ├── Http/
│   │   ├── Request.php                Input wrapper (get_int, get_textarea, …)
│   │   └── Rest_Api.php               9 REST endpoints
│   │
│   ├── Public/
│   │   └── Public_Loader.php          Front-end form submission handler
│   │
│   ├── Tools/
│   │   ├── Abstract_Exporter.php      Base CSV exporter
│   │   ├── Prayed_Counts_Exporter.php
│   │   ├── Prayer_Requests_Exporter.php
│   │   ├── Requesters_Exporter.php
│   │   ├── Settings_Exporter.php
│   │   └── Tools_Admin_Page.php
│   │
│   └── Util/
│       ├── Autoloader.php
│       ├── Cron_Handler.php           Scheduled prayer-count notifications
│       ├── Notifier.php
│       ├── Profanity_Filter.php
│       ├── Rate_Limiter.php
│       └── Recaptcha.php
│
├── templates/
│   ├── admin/
│   │   ├── dashboard.php
│   │   ├── prayer-warrior-requests.php  Read-only request list for prayer warriors
│   │   ├── request-detail.php         Single request + notes panel
│   │   ├── requesters.php             List + branches to detail view
│   │   ├── requests.php
│   │   └── tools/exports.php
│   └── blocks/
│       ├── prayer-form.php
│       ├── prayer-wall.php
│       └── user-prayer-history.php
│
├── assets/
│   ├── css/{admin,iconfont,public}.css
│   ├── fonts/intercessor.{eot,svg,ttf,woff}
│   └── js/
│       ├── admin/admin.js
│       ├── blocks/{prayer-form,prayer-wall,prayer-history}/
│       └── public/{prayer-form,prayer-wall,prayer-history}.js
│
├── languages/
└── tests/
    ├── Unit/     (~200 test methods across 16 files)
    └── Integration/
```

---

## Database Schema

Six tables are created automatically on activation.

| Table | Purpose |
|---|---|
| `intercessor_prayer_requests` | Prayer request rows |
| `intercessor_requesters` | Deduplicated submitter records |
| `intercessor_prayer_history` | Immutable status-change audit log |
| `intercessor_prayer_notes` | Internal moderator annotations on requests |
| `intercessor_prayed_counts` | "I prayed for this" interaction counts |
| `intercessor_requester_notes` | Private admin notes on requester records |

---

## Roles and Capabilities

Three custom roles and six custom capabilities are registered on activation.

| Capability | `administrator` | `prayer_manager` | `prayer_warrior` |
|---|:---:|:---:|:---:|
| `edit_prayers` | ✓ | ✓ | — |
| `manage_prayer_settings` | ✓ | ✓ | — |
| `view_prayer_reports` | ✓ | ✓ | — |
| `export_prayer_reports` | ✓ | ✓ | — |
| `view_prayer_sensitive_data` | ✓ | ✓ | — |
| `read_private_prayers` | — | — | ✓ |

Prayer warriors use the read-only Prayer Requests list (approved and private requests, no requester contact details) and can record prayers; they cannot moderate, view reports, or export data.

The `requester` role (`read` only) is assigned automatically when a guest submitter is registered as a WordPress user.

---

## REST API

Base namespace: `/wp-json/intercessor/v1/`

| Method | Endpoint | Required capability |
|---|---|---|
| GET | `/requests` | Public |
| GET | `/requests/{id}` | Public |
| POST | `/requests` | Configurable |
| POST | `/requests/{id}/status` | `edit_prayers` |
| GET | `/requests/{id}/history` | Public |
| GET | `/requests/{id}/notes` | `edit_prayers` |
| POST | `/requests/{id}/notes` | `edit_prayers` |
| DELETE | `/requests/{id}/notes/{nid}` | `edit_prayers` |
| GET | `/requesters` | `edit_prayers` |

---

## Requester Detail Page

Accessible at `?page=intercessor-requesters&requester_id={id}`. Five tabs:

| Tab | Content |
|---|---|
| **Overview** | Avatar, name, email, WP user link, status, registration date, prayer stats |
| **Prayer Requests** | Paginated table of all prayers with status and prayed-for count |
| **History** | Status-change timeline grouped by prayer request |
| **Notes** | Requester notes (create/delete) + prayer notes (read-only cross-reference) |
| **Delete** | Confirmation panel with optional associated data removal |

---

## Development

```bash
# Install JS dependencies
npm install

# Build blocks for production
npm run build

# Watch for changes during development
npm run start

# Package for WordPress.org (builds + zips)
npm run package

# Zip without rebuilding
npm run zip

# Lint PHP (requires PHP_CodeSniffer + WordPress Coding Standards)
./vendor/bin/phpcs

# Fix PHP coding standards automatically
./vendor/bin/phpcbf

# Run unit tests
./vendor/bin/phpunit --testsuite unit

# Run integration tests (requires a test WordPress install)
./vendor/bin/phpunit --testsuite integration
```

### Generating a translation template

```bash
wp i18n make-pot . languages/intercessor.pot --domain=intercessor
```

---

## Contributing

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/my-feature`.
3. Commit your changes following [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).
4. Open a Pull Request against `main`.

Please open an issue before starting work on significant changes.

---

## Changelog

### 1.1.1
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
* Fixed a duplicate "Prayer Requests" menu entry for users who can both moderate and read private requests.
* Fixed `Admin_Loader::intercessor_is_admin_page()` returning an undefined variable, and `get_admin_current_screen()` calling a method that does not exist.

### 1.1.0
* Added single prayer request detail page in admin with notes panel and live prayer count.
* Added prayer-level notes with optional private flag.
* Added anonymous prayer recording — non-logged-in visitors can now use the Prayer Wall "I prayed for this" button.
* Improved prayed-count tracking — repeat interactions increment a counter rather than inserting duplicate rows.
* Notification cron job reschedules automatically on settings change.
* Internal: centralised settings schema into a Registry class.
* Fixed missing class imports in Admin_Loader causing fatal errors on moderation and note actions.

### 1.0.1
* Added private prayer request option on the submission form.
* Added duplicate request prevention (blocks resubmission of the same subject by the same requester).
* Added status filter bar to the admin Prayer Requests list (All / Pending / Approved / Rejected / Archived / Private).
* Fixed "Settings saved." notice not appearing after saving settings.
* Fixed double blank lines throughout PHP source files.
* Redesigned the Prayer History block — guest login prompt is now a styled card with icon; authenticated view uses a card-per-request layout.

### 1.0.0
- Initial public release.

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for the full text.
