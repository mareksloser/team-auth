<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * Detects the logo format from its bytes (magic numbers), not from the file name or the
 * browser-supplied MIME type. Pure, no I/O and no dependency on ext-fileinfo.
 */
final class LogoType
{
    public const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    public static function detect(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, 'RIFF') && 'WEBP' === substr($bytes, 8, 4)) {
            return 'image/webp';
        }
        // SVG: optional BOM, XML prolog, comments or doctype, then an <svg> root element.
        $head = ltrim(substr($bytes, 0, 2048), "\xEF\xBB\xBF \t\r\n");
        if (preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*|<!DOCTYPE[^>]*>\s*)*<svg[\s>]/is', $head)) {
            return 'image/svg+xml';
        }

        return null;
    }
}
