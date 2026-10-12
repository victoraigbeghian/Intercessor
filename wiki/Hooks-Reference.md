# Hooks Reference

Intercessor provides actions and filters for developers to extend its behavior without modifying core plugin files.

## Actions

### intercessor_before_pray_notification_run
Fires before the scheduled prayer-count notification cron job processes requests.

```php
do_action( 'intercessor_before_pray_notification_run' );
```

### intercessor_after_pray_notification_run
Fires after the notification cron job completes. Receives the number of notifications sent.

```php
do_action( 'intercessor_after_pray_notification_run', int $updated );
```

### intercessor_admin_screen_options
Fires when the Screen Options are registered on an Intercessor list table page. Receives the page slug (`intercessor-requests`, `intercessor-private-requests`, or `intercessor-requesters`).

```php
do_action( 'intercessor_admin_screen_options', string $screen );
```

### intercessor_prayers_contextual_help
Fires after the Help tabs are added to the Prayer Requests page (both the moderation and prayer warrior versions). Receives the `WP_Screen` object, so you can add your own tabs.

```php
do_action( 'intercessor_prayers_contextual_help', WP_Screen $screen );
```

### intercessor_email_confirmed
Fires when a user confirms their email address via the confirmation link. Receives the WordPress user ID.

```php
do_action( 'intercessor_email_confirmed', int $user_id );
```

**Example:**
```php
add_action( 'intercessor_email_confirmed', function( $user_id ) {
    // Assign the user a specific role after confirmation
    $user = get_userdata( $user_id );
    if ( $user ) {
        $user->set_role( 'prayer_warrior' );
    }
} );
```

### intercessor_settings_field_{type}
Fires when rendering a custom settings field type. The `{type}` is the field's `type` value. Receives the field arguments array and the current value.

```php
do_action( 'intercessor_settings_field_my_custom_type', array $args, mixed $value );
```

## Filters

### intercessor_report_views
Filter the array of available report views on the Reports page.

```php
$views = apply_filters( 'intercessor_report_views', array $views );
```

**Example:**
```php
add_filter( 'intercessor_report_views', function( $views ) {
    $views['my_custom_report'] = My_Custom_Report_View::class;
    return $views;
} );
```

### intercessor_is_admin_page
Filter whether the current request is an Intercessor admin page.

```php
$is_admin_page = apply_filters( 'intercessor_is_admin_page', bool $is_admin_page );
```

### intercessor_requester_tabs
Filter the tabs displayed on the requester detail page. Receives the tabs array and the requester object.

```php
$tabs = apply_filters( 'intercessor_requester_tabs', array $tabs, object $requester );
```

### intercessor_pray_notification_to
Filter the recipient email address for prayer-count notifications. Receives the email, the prayer request object, and the requester object.

```php
$to = apply_filters( 'intercessor_pray_notification_to', string $to, object $request, object $requester );
```

### intercessor_pray_notification_subject
Filter the email subject line for prayer-count notifications.

```php
$subject = apply_filters( 'intercessor_pray_notification_subject', string $subject, object $request, int $total_prayers );
```

### intercessor_pray_notification_body
Filter the email body for prayer-count notifications.

```php
$body = apply_filters( 'intercessor_pray_notification_body', string $body, object $request, object $requester, int $total_prayers );
```

**Example:**
```php
add_filter( 'intercessor_pray_notification_body', function( $body, $request, $requester, $total ) {
    // Append a custom footer to notification emails
    return $body . "\n\n— Sent with love from Our Church";
}, 10, 4 );
```

### intercessor_confirmation_email_args
Filter the arguments passed to `wp_mail()` for the email confirmation message sent during user registration.

```php
$args = apply_filters( 'intercessor_confirmation_email_args', array $args );
```

The `$args` array contains `to`, `subject`, `message`, and `headers` keys.
