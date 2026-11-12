# REST API

Intercessor exposes a REST API under the namespace `intercessor/v1`. All endpoints require authentication and appropriate capabilities unless noted otherwise.

**Base URL:** `/wp-json/intercessor/v1/`

## Endpoints

### Prayer Requests

#### List Requests
```
GET /requests
```
Returns a paginated list of prayer requests. Requires `edit_prayers` capability.

**Parameters:** Standard WordPress REST pagination (`page`, `per_page`).

#### Get Single Request
```
GET /requests/{id}
```
Returns a single prayer request by ID. Public for approved, public requests. Any other request is returned only to callers with the `edit_prayers` capability; everyone else receives `404`.

#### Create Request
```
POST /requests
```
Creates a new prayer request. Used by the Prayer Form block for AJAX submissions.

#### Update Request Status
```
PATCH /requests/{id}/status
```
Updates the status of a prayer request (approve, reject, mark as spam). Requires `edit_prayers` capability.

### Prayer History (User's Own Requests)

#### Get User's Request History
```
GET /requests/{id}/history
```
Returns the status-change history for a specific request. Follows the same visibility rule as `GET /requests/{id}`. Callers without `edit_prayers` receive only `id`, `prayer_request_id`, `old_status`, `new_status` and `date_created`; `actor_user_id` and `note` are moderator-only.

### Notes

#### List Notes
```
GET /requests/{id}/notes
```
Returns moderator and prayer notes for a request. Requires `edit_prayers` capability.

#### Add Note
```
POST /requests/{id}/notes
```
Adds a new note to a request. Requires `edit_prayers` capability.

#### Delete Note
```
DELETE /requests/{id}/notes/{nid}
```
Deletes a specific note. Requires `edit_prayers` capability.

### Requesters

#### List Requesters
```
GET /requesters
```
Returns a list of people who have submitted prayer requests. Requires `edit_prayers` capability.

## Authentication

The API uses WordPress cookie authentication (for logged-in users in the browser) and nonce verification. For external integrations, use WordPress application passwords or a JWT authentication plugin.

## AJAX Endpoints

In addition to the REST API, Intercessor uses WordPress AJAX for certain front-end interactions:

| Action | Description |
|--------|-------------|
| `intercessor_pray` | Record an "I prayed for this" action on the Prayer Wall |
| `intercessor_update_own_request` | Edit a user's own pending request (Prayer History) |
| `intercessor_delete_own_request` | Delete a user's own request (Prayer History) |

These are called from the front-end JavaScript and are nonce-protected.
