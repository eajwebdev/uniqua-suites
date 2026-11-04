<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** Base32 of the RFC 6238 SHA-1 seed "12345678901234567890". */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_codes_match_the_rfc_6238_vectors(): void
    {
        // The RFC lists 8 digits; 6-digit codes are the last six of each.
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, Totp::code(self::SECRET, (int) floor($time / 30)));
        }
    }

    public function test_verify_allows_one_step_of_clock_drift_only(): void
    {
        $at = 1234567890;
        $step = (int) floor($at / 30);

        $this->assertSame($step, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step), null, $at));
        $this->assertSame($step - 1, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 1), null, $at));
        $this->assertSame($step + 1, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step + 1), null, $at));
        $this->assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 2), null, $at));
        $this->assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, $step + 2), null, $at));
    }

    public function test_a_used_step_cannot_be_replayed(): void
    {
        $at = 1234567890;
        $step = (int) floor($at / 30);
        $code = Totp::code(self::SECRET, $step);

        $this->assertNull(Totp::verify(self::SECRET, $code, $step, $at));
        $this->assertSame($step, Totp::verify(self::SECRET, $code, $step - 1, $at));
    }

    public function test_malformed_codes_are_rejected_and_spaces_are_tolerated(): void
    {
        $at = 1234567890;
        $code = Totp::code(self::SECRET, (int) floor($at / 30));

        $this->assertNull(Totp::verify(self::SECRET, 'abcdef', null, $at));
        $this->assertNull(Totp::verify(self::SECRET, '12345', null, $at));
        $this->assertNotNull(Totp::verify(self::SECRET, substr($code, 0, 3).' '.substr($code, 3), null, $at));
    }

    public function test_generated_secrets_round_trip_through_verify(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotNull(Totp::verify($secret, Totp::code($secret, (int) floor(time() / 30))));
    }

    public function test_uri_is_what_authenticator_apps_scan(): void
    {
        $uri = Totp::uri(self::SECRET, 'owner@example.test', 'UNIQA SUITES');

        $this->assertStringStartsWith('otpauth://totp/UNIQA%20SUITES:owner%40example.test?', $uri);
        $this->assertStringContainsString('secret='.self::SECRET, $uri);
        $this->assertStringContainsString('issuer=UNIQA%20SUITES', $uri);
    }
}
