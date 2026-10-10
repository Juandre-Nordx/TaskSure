<?php

return [
    'push_enabled' => (bool) env('MOBILE_PUSH_ENABLED', false),
    'fcm_service_account' => env('FCM_SERVICE_ACCOUNT_JSON'),
    'apns_key' => env('APNS_PRIVATE_KEY'),
    'apns_key_id' => env('APNS_KEY_ID'),
    'apns_team_id' => env('APNS_TEAM_ID'),
    'apns_bundle_id' => env('APNS_BUNDLE_ID', 'za.co.tasksure.employee'),
    'apns_sandbox' => (bool) env('APNS_SANDBOX', false),
];
