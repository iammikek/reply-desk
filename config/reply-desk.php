<?php

$weekday = [['08:00', '20:00']];

return [

    /*
    |--------------------------------------------------------------------------
    | Note store
    |--------------------------------------------------------------------------
    |
    | memory keeps notes in the local database so the desk can be shown
    | without a token. hubspot reads and writes clock notes on the portal
    | named by HUBSPOT_PORTAL_ID.
    |
    */

    'store' => env('REPLY_DESK_STORE', 'memory'),

    'portal_id' => env('HUBSPOT_PORTAL_ID', ''),

    'token' => env('HUBSPOT_ACCESS_TOKEN', ''),

    'api_base' => env('HUBSPOT_API_BASE', 'https://api.hubapi.com'),

    /*
    |--------------------------------------------------------------------------
    | Practice hours
    |--------------------------------------------------------------------------
    |
    | The clock, the due time, and Replied in use these ranges. An empty day
    | is closed. More than one range on a day leaves a mid-day gap. Timezone
    | and interval can come from the environment; the day ranges live here.
    |
    */

    'timezone' => env('REPLY_DESK_TIMEZONE', 'Europe/London'),

    'interval_hours' => (int) env('REPLY_DESK_INTERVAL_HOURS', 24),

    'hours' => [
        'monday' => $weekday,
        'tuesday' => $weekday,
        'wednesday' => $weekday,
        'thursday' => $weekday,
        'friday' => $weekday,
        'saturday' => [],
        'sunday' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Practice mailbox
    |--------------------------------------------------------------------------
    |
    | Optional IMAP + SMTP for the practice account. When host and credentials
    | are empty, the desk keeps local drafts and the Laravel mailer for send.
    | Poll on/off and interval are controlled in the panel, not here.
    |
    */

    'mailbox' => [
        'driver' => env('REPLY_DESK_MAILBOX_DRIVER', ''),
        'from_address' => env('REPLY_DESK_MAILBOX_FROM', env('MAIL_FROM_ADDRESS', '')),
        'from_name' => env('REPLY_DESK_MAILBOX_FROM_NAME', env('MAIL_FROM_NAME', 'Reply desk')),
        'imap_host' => env('REPLY_DESK_IMAP_HOST', ''),
        'imap_port' => (int) env('REPLY_DESK_IMAP_PORT', 993),
        'imap_encryption' => env('REPLY_DESK_IMAP_ENCRYPTION', 'ssl'),
        'imap_username' => env('REPLY_DESK_IMAP_USERNAME', ''),
        'imap_password' => env('REPLY_DESK_IMAP_PASSWORD', ''),
        'inbox_folder' => env('REPLY_DESK_IMAP_INBOX', 'INBOX'),
        'drafts_folder' => env('REPLY_DESK_IMAP_DRAFTS', 'Drafts'),
        'smtp_host' => env('REPLY_DESK_SMTP_HOST', ''),
        'smtp_port' => (int) env('REPLY_DESK_SMTP_PORT', 587),
        'smtp_encryption' => env('REPLY_DESK_SMTP_ENCRYPTION', 'tls'),
        'smtp_username' => env('REPLY_DESK_SMTP_USERNAME', ''),
        'smtp_password' => env('REPLY_DESK_SMTP_PASSWORD', ''),
    ],

];
