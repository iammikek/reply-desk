<?php

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

];
