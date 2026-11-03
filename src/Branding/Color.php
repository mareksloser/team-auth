<?php

declare(strict_types=1);

namespace App\Branding;

/** Hex colour helpers. Pure, no I/O. */
final class Color
{
    /** "#ABC" / "abc" / "#aabbcc" → "#aabbcc"; empty → null; anything else → \InvalidArgumentException. */
    public static function normalize(?string $value): ?string
    {
        $value = strtolower(ltrim(trim((string) $value), '#'));
        if ('' === $value) {
            return null;
        }
        if (preg_match('/^[0-9a-f]{3}$/', $value)) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/', $value)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a hex colour.', $value));
        }

        return '#'.$value;
    }

    /** Black or white, whichever reads better on $background (WCAG contrast ratio). */
    public static function readableInk(string $background): string
    {
        $l = self::luminance($background);
        $contrastWithWhite = 1.05 / ($l + 0.05);
        $contrastWithBlack = ($l + 0.05) / 0.05;

        return $contrastWithWhite >= $contrastWithBlack ? '#ffffff' : '#111111';
    }

    /** WCAG relative luminance, 0 (black) … 1 (white). */
    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channel = static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));
    }
}
