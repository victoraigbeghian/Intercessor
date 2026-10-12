# Roles and Capabilities

Intercessor registers three custom WordPress roles and six custom capabilities. The `administrator` role receives all capabilities automatically on activation.

## Roles

### Prayer Manager
The primary moderation role. Can review, approve, reject, and manage all prayer requests, view reports, export data, and configure settings.

**Capabilities:** `edit_prayers`, `manage_prayer_settings`, `view_prayer_reports`, `export_prayer_reports`, `view_prayer_sensitive_data`, `read_private_prayers`

### Prayer Warrior
A trusted community member who can view all prayer requests including private ones and access reports, but cannot moderate or change settings.

**Capabilities:** `read_private_prayers`

### Requester
A basic role for users who submit prayer requests. Can view and manage only their own requests through the Prayer History block.

**Capabilities:** none of the Intercessor-specific capabilities (they interact through the front-end blocks only)

## Capabilities

| Capability | Description |
|------------|-------------|
| `edit_prayers` | Approve, reject, edit, and delete prayer requests |
| `manage_prayer_settings` | Access and modify Intercessor settings |
| `view_prayer_reports` | View the Reports dashboard |
| `export_prayer_reports` | Export data as CSV from the Tools page |
| `view_prayer_sensitive_data` | View requester email addresses and personal details |
| `read_private_prayers` | View prayer requests marked as private |

## Assigning Roles

Roles can be assigned to users through the standard WordPress **Users** screen. You can also choose which existing role acts as the moderator under **Intercessor → Settings → General → Moderator Role**.

## Programmatic Access

Roles and capabilities are defined as constants in `Intercessor\Roles`:

```php
use Intercessor\Roles;

// Check capability
if ( current_user_can( Roles::CAP_EDIT_PRAYERS ) ) {
    // user can moderate
}

// Role slug
$role = Roles::ROLE_PRAYER_MANAGER; // 'prayer_manager'
```
