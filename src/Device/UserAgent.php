<?php

declare(strict_types=1);

namespace App\Device;

/** Human-readable "Browser · OS" from a User-Agent string. Deliberately simple; pure. */
final class UserAgent
{
    public static function describe(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            null !== $browser && null !== $os => "$browser · $os",
            null !== $browser => $browser,
            null !== $os => $os,
            default => 'Neznámé zařízení',
        };
    }
}
