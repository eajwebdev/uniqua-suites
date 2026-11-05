<?php

return [
    'display_timezone' => env('UNIQA_DISPLAY_TIMEZONE', 'Asia/Manila'),

    // One-click sign-in on the login page. Production ignores this: see App\Support\DemoLogin.
    'demo' => (bool) env('DEMO', false),

    // The only account created on a fresh non-test database. Its random password is
    // printed once by DatabaseSeeder; all other staff are invited by the owner.
    'bootstrap_owner' => [
        'name' => env('UNIQA_OWNER_NAME', 'Owner'),
        'email' => env('UNIQA_OWNER_EMAIL', 'owner@uniqa-suites.test'),
    ],

    // Official channels only. Blank means "not yet supplied by the owner".
    'contact' => [
        'messenger_url' => env('UNIQA_MESSENGER_URL'),
        'instagram_url' => env('UNIQA_INSTAGRAM_URL'),
        'facebook_url' => env('UNIQA_FACEBOOK_URL'),
        'phone' => env('UNIQA_CONTACT_PHONE'),
        'email' => env('UNIQA_CONTACT_EMAIL'),
    ],
];
