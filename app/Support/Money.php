<?php

namespace App\Support;

class Money
{
    public const RULE = 'regex:/^\d{1,12}(\.\d{1,2})?$/';

    /** Peso string to centavos without passing through a float. */
    public static function cents(mixed $pesos): ?int
    {
        if ($pesos === null || $pesos === '') {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $pesos, 2), 2, '0');

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function format(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }
        $sign = $cents < 0 ? '-' : '';

        return $sign.'₱'.number_format(abs($cents) / 100, 2);
    }

    public static function pesos(?int $cents): ?string
    {
        return $cents === null ? null : number_format($cents / 100, 2, '.', '');
    }
}
