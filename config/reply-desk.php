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

];
