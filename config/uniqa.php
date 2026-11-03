<?php

return [
    'display_timezone' => env('UNIQA_DISPLAY_TIMEZONE', 'Asia/Manila'),

    // Official channels only. Blank means "not yet supplied by the owner".
    'contact' => [
        'messenger_url' => env('UNIQA_MESSENGER_URL'),
        'instagram_url' => env('UNIQA_INSTAGRAM_URL'),
        'facebook_url' => env('UNIQA_FACEBOOK_URL'),
        'phone' => env('UNIQA_CONTACT_PHONE'),
        'email' => env('UNIQA_CONTACT_EMAIL'),
    ],
];
