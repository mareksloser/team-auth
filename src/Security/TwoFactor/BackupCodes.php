<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

/** One-time recovery codes. Only SHA-256 hashes are stored; the codes are random, so a fast hash is fine. Pure. */
final class BackupCodes
{
    public const COUNT = 10;
    // No 0/O, 1/I/L: codes get copied from paper.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @return list<string> codes formatted as XXXX-XXXX */
    public static function generate(int $count = self::COUNT): array
    {
        $codes = [];
        for ($i = 0; $i < $count; ++$i) {
            $code = '';
            for ($j = 0; $j < 8; ++$j) {
                $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($code, 0, 4).'-'.substr($code, 4);
        }

        return $codes;
    }

    /** Ignores case, spaces and dashes, so "abcd efgh" matches "ABCD-EFGH". */
    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper((string) preg_replace('/[\s-]+/', '', $code)));
    }
}
