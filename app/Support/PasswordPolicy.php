<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * One password rule for changing and resetting a password: at least 8 characters with a letter and a
 * number, at most 72 (bcrypt reads no further), and not one of the passwords guessed first.
 * The same list is mirrored in resources/js/components/PasswordStrength.tsx for the live checklist.
 */
class PasswordPolicy
{
    public const MIN = 8;

    public const MAX = 72;

    // Lower-cased. Includes the seeded starting password, so nobody keeps it.
    public const COMMON = [
        'password@123', 'password123', 'password1', 'passw0rd', 'p@ssw0rd', 'p@ssword1', 'qwerty123', 'qwerty12',
        'abc12345', 'abcd1234', '1q2w3e4r', 'iloveyou1', 'welcome1', 'welcome123', 'admin123', 'admin1234',
        'letmein1', 'uniqa123', 'uniqasuites1', 'uniqasuites123', 'grabcam123', 'changeme1',
    ];

    public static function rule(): array
    {
        return [
            'max:'.self::MAX,
            Password::min(self::MIN)->letters()->numbers(),
            function (string $attribute, mixed $value, \Closure $fail) {
                if (in_array(mb_strtolower((string) $value), self::COMMON, true)) {
                    $fail('That password is too easy to guess. Choose something less common.');
                }
            },
        ];
    }
}
