<?php

declare(strict_types=1);

namespace App\Support;

final class Random
{
    /** Brez 0/O, 1/I/L: gost kodo prebere iz SMS ali jo pove po telefonu. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function code(int $length = 8): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::CODE_ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** 256 bitov, base64url brez polnila = 43 znakov. */
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
