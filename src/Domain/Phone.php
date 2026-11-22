<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Telefonska številka v obliki E.164 (+38641234567).
 *
 * Ena oblika v bazi pomeni, da iskanje "041 234 567" in "+386 41 234 567"
 * najde isto rezervacijo in da se primerjava s številko klicatelja iz SIP ujema.
 */
final class Phone
{
    public static function normalize(string $raw, string $countryCode): ?string
    {
        $s = preg_replace('/[\s\-\/().]/', '', trim($raw)) ?? '';
        if (str_starts_with($s, '00')) {
            $s = '+' . substr($s, 2);
        } elseif (str_starts_with($s, '0')) {
            $s = '+' . $countryCode . substr($s, 1);
        }
        return preg_match('/^\+[1-9]\d{7,14}$/', $s) === 1 ? $s : null;
    }
}
