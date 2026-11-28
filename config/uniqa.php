<?php

return [
    'display_timezone' => env('UNIQA_DISPLAY_TIMEZONE', 'Asia/Manila'),

    // One-click sign-in on the login page. Production ignores this: see App\Support\DemoLogin.
    'demo' => (bool) env('DEMO', false),

    // Official UNIQA SUITES channels, changeable in .env. Blank means "not supplied", and the site shows nothing for it.
    'contact' => [
        'messenger_url' => env('UNIQA_MESSENGER_URL', 'https://m.me/uniqasuitescdo'),
        'instagram_url' => env('UNIQA_INSTAGRAM_URL'),
        'tiktok_url' => env('UNIQA_TIKTOK_URL'),
        'facebook_url' => env('UNIQA_FACEBOOK_URL', 'https://www.facebook.com/uniqasuitescdo'),
        'phone' => env('UNIQA_CONTACT_PHONE'),
        'email' => env('UNIQA_CONTACT_EMAIL'),
    ],

    // Alerts on staff phones, tablets and computers (Web Push). Make the keys once with: php artisan uniqa:push-keys
    // Changing the keys stops every device's alerts until each person turns them on again.
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // Push services contact this if alerts misbehave: an https:// address or mailto:.
        'subject' => env('VAPID_SUBJECT'),
    ],

    // Who the client pays for the system, shown on the locked screen and the subscription page.
    'vendor' => [
        'name' => 'EAJ Web Development Services',
        'person' => 'Edwin Abril Jr',
        'phone' => null,
        'email' => null,
    ],

    // Grab Cam Rental's public page at /grabcam. Only that page may be embedded by other sites,
    // and only by the origins listed here (a CSP frame-ancestors source list).
    'grabcam' => [
        // Inquiries open a Messenger chat with the Grab Cam page, with the camera and days already written.
        // Only an m.me link can carry that message; a facebook.com/profile.php?id=N link is turned into m.me/N.
        'messenger_url' => env('GRABCAM_MESSENGER_URL', 'https://m.me/61582134053068'),
        'facebook_url' => env('GRABCAM_FACEBOOK_URL', 'https://www.facebook.com/profile.php?id=61582134053068'),
        'frame_ancestors' => env('GRABCAM_FRAME_ANCESTORS', "'self' https:"),
    ],
];
