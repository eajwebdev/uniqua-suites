<?php

return [
    'display_timezone' => env('UNIQA_DISPLAY_TIMEZONE', 'Asia/Manila'),

    // One-click sign-in on the login page. Production ignores this: see App\Support\DemoLogin.
    'demo' => (bool) env('DEMO', false),

    // Official UNIQA SUITES channels, changeable in .env. Blank means "not supplied", and the site shows nothing for it.
    'contact' => [
        'messenger_url' => env('UNIQA_MESSENGER_URL', 'https://m.me/uniqasuitescdo'),
        'instagram_url' => env('UNIQA_INSTAGRAM_URL'),
        'facebook_url' => env('UNIQA_FACEBOOK_URL', 'https://www.facebook.com/uniqasuitescdo'),
        'phone' => env('UNIQA_CONTACT_PHONE'),
        'email' => env('UNIQA_CONTACT_EMAIL'),
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
