<?php

declare(strict_types=1);

namespace App\Device;

/** Czech relative time ("před 5 min"). Pure. */
final class TimeAgo
{
    public static function format(\DateTimeInterface $time, \DateTimeInterface $now): string
    {
        $seconds = $now->getTimestamp() - $time->getTimestamp();

        return match (true) {
            $seconds < 90 => 'právě teď',
            $seconds < 3600 => sprintf('před %d min', intdiv($seconds, 60)),
            $seconds < 86400 => sprintf('před %d h', intdiv($seconds, 3600)),
            $seconds < 2 * 86400 => 'včera',
            $seconds < 7 * 86400 => sprintf('před %d dny', intdiv($seconds, 86400)),
            default => $time->format('j. n. Y'),
        };
    }
}
