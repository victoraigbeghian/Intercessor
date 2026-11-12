# Database Schema

Intercessor creates six custom tables through its built-in data layer (see [Architecture](Architecture)). All tables use the WordPress table prefix (e.g., `wp_intercessor_prayer_requests`). Each table stores its schema version in the `wp_options` table under the key `{table_name}_db_version`.

## Tables

### intercessor_prayer_requests

The primary table storing all submitted prayer requests.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `requester_id` | BIGINT UNSIGNED | Foreign key to `intercessor_requesters` |
| `prayer_request` | LONGTEXT | The prayer request content |
| `status` | VARCHAR(20) | Request status: `pending`, `approved`, `rejected`, `spam` |
| `is_anonymous` | TINYINT(1) | Whether the request was submitted anonymously |
| `ip_address` | VARCHAR(45) | Submitter's IP address |
| `date_created` | DATETIME | When the request was submitted |
| `date_modified` | DATETIME | When the request was last modified |

### intercessor_requesters

People who have submitted prayer requests, linked to WordPress user accounts where applicable.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `wp_user_id` | BIGINT UNSIGNED | WordPress user ID (0 if guest) |
| `name` | VARCHAR(255) | Requester's display name |
| `email` | VARCHAR(255) | Requester's email address |
| `date_created` | DATETIME | When the requester record was created |
| `date_modified` | DATETIME | Last modification date |

### intercessor_prayed_counts

Records each "I prayed for this" action from the Prayer Wall.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `prayer_id` | BIGINT UNSIGNED | Foreign key to `intercessor_prayer_requests` |
| `user_id` | BIGINT UNSIGNED | WordPress user ID of the person who prayed |
| `date_created` | DATETIME | When the prayer was recorded |

### intercessor_prayer_history

Activity log tracking status changes and other events for each prayer request.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `prayer_id` | BIGINT UNSIGNED | Foreign key to `intercessor_prayer_requests` |
| `user_id` | BIGINT UNSIGNED | User who performed the action |
| `action` | VARCHAR(50) | The action taken (e.g., `approved`, `rejected`, `edited`) |
| `note` | TEXT | Optional description of the action |
| `date_created` | DATETIME | When the action occurred |

### intercessor_prayer_notes

Moderator notes attached to prayer requests.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `prayer_id` | BIGINT UNSIGNED | Foreign key to `intercessor_prayer_requests` |
| `user_id` | BIGINT UNSIGNED | User who wrote the note |
| `note` | TEXT | Note content |
| `date_created` | DATETIME | When the note was created |

### intercessor_requester_notes

Notes attached to requester profiles (separate from prayer-level notes).

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `requester_id` | BIGINT UNSIGNED | Foreign key to `intercessor_requesters` |
| `user_id` | BIGINT UNSIGNED | User who wrote the note |
| `note` | TEXT | Note content |
| `date_created` | DATETIME | When the note was created |

## Querying Data

Each table has a corresponding Query class in `Intercessor\Database\Query\` that extends the data layer's base Query (`Intercessor\BerlinDB\Query`). Example:

```php
use Intercessor\Database\Query\Prayer_Request_Query;

$query = new Prayer_Request_Query();

// Get approved requests, newest first
$results = $query->query( [
    'status'  => 'approved',
    'orderby' => 'date_created',
    'order'   => 'DESC',
    'number'  => 10,
] );
```

Row objects are typed instances of classes in `Intercessor\Database\Row\` with property access for each column.
