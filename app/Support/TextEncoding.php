<?php

namespace App\Support;

class TextEncoding
{
    public static function isValidUtf8(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        return mb_check_encoding($value, 'UTF-8');
    }

    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\0", '', $value);

        if (! self::isValidUtf8($value)) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1251, Windows-1252, ISO-8859-1');
            $value = is_string($converted) && self::isValidUtf8($converted)
                ? $converted
                : mb_scrub($value, 'UTF-8');
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (function_exists('iconv')) {
            $iconv = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($iconv)) {
                $value = $iconv;
            }
        }

        for ($i = 0; $i < 3; $i++) {
            $repaired = self::repairMojibake($value);

            if ($repaired === $value) {
                break;
            }

            $value = $repaired;
        }

        $value = str_replace([self::replacementChar(), 'ï¿½'], '', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = preg_replace('/[ \t\r\n]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+([,.;:!?])/u', '$1', $value) ?? $value;
        $value = trim($value);

        return self::isValidUtf8($value) ? $value : mb_scrub($value, 'UTF-8');
    }

    public static function preview(?string $value, int $limit = 120): string
    {
        $clean = self::clean($value) ?? '';
        $clean = trim(strip_tags($clean));
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        if ($clean === '') {
            return '[empty]';
        }

        if (mb_strlen($clean) <= $limit) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, max(1, $limit - 1))).'...';
    }

    public static function rawPreview(?string $value, int $limit = 120): string
    {
        if ($value === null || $value === '') {
            return '[empty]';
        }

        if (! self::isValidUtf8($value)) {
            $value = mb_scrub($value, 'UTF-8');
        }

        $value = trim(strip_tags($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        if ($value === '') {
            return '[empty]';
        }

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(1, $limit - 1))).'...';
    }

    public static function issue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! self::isValidUtf8($value)) {
            return 'invalid_utf8';
        }

        if (str_contains($value, self::replacementChar()) || str_contains($value, 'ï¿½')) {
            return 'replacement_character';
        }

        if (preg_match('/\?{2,}/u', $value) === 1) {
            return 'repeated_question_marks';
        }

        if (preg_match('/&(amp|quot|nbsp|lt|gt|#[0-9]+|#x[0-9a-f]+);/iu', $value) === 1) {
            return 'html_entity_artifacts';
        }

        if (preg_match('/(?:Ð|Ñ|Â|â€|â€™|â€œ|â€\x9d|â€“|â€”|Гђ|Г‘|Г‚|Гў)/u', $value) === 1) {
            return 'mojibake';
        }

        $clean = self::clean($value);
        if ($clean !== $value && self::looksEncodingRelated($value, $clean ?? '')) {
            return 'cleaned_encoding_artifacts';
        }

        return null;
    }

    public static function hasIssue(?string $value): bool
    {
        return self::issue($value) !== null;
    }

    public static function isDisplaySafe(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        return ! in_array(self::issue($value), [
            'invalid_utf8',
            'replacement_character',
            'repeated_question_marks',
            'mojibake',
        ], true);
    }

    public static function cleanRecursive(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::clean($value);
        }

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                $cleanKey = is_string($key) ? (self::clean($key) ?? $key) : $key;
                $clean[$cleanKey] = self::cleanRecursive($item);
            }

            return $clean;
        }

        return $value;
    }

    private static function repairMojibake(string $value): string
    {
        if (preg_match('/(?:Ð|Ñ|Â|â€|â€™|â€œ|â€“|â€”|Гђ|Г‘|Г‚|Гў)/u', $value) !== 1) {
            return $value;
        }

        $candidates = array_filter([
            self::decodeMojibakeBytes($value, 'Windows-1251'),
            self::decodeMojibakeBytes($value, 'Windows-1252'),
            @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1'),
            @mb_convert_encoding($value, 'UTF-8', 'Windows-1252'),
        ], 'is_string');

        $best = $value;
        $bestScore = self::qualityScore($value);

        foreach ($candidates as $candidate) {
            $score = self::qualityScore($candidate);

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private static function qualityScore(string $value): int
    {
        preg_match_all('/\p{Cyrillic}/u', $value, $cyrillic);
        preg_match_all('/(?:Ð|Ñ|Â|â€|â€™|â€œ|â€“|â€”|Гђ|Г‘|Г‚|Гў|'.preg_quote(self::replacementChar(), '/').')/u', $value, $bad);

        return count($cyrillic[0] ?? []) - (count($bad[0] ?? []) * 12);
    }

    private static function decodeMojibakeBytes(string $value, string $sourceEncoding): ?string
    {
        if (! function_exists('iconv')) {
            return null;
        }

        $bytes = @iconv('UTF-8', $sourceEncoding.'//IGNORE', $value);

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        $scrubbed = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');

        return is_string($scrubbed) && mb_check_encoding($scrubbed, 'UTF-8') ? $scrubbed : null;
    }

    private static function looksEncodingRelated(string $old, string $new): bool
    {
        return str_contains($old, "\0")
            || str_contains($old, self::replacementChar())
            || str_contains($old, 'ï¿½')
            || preg_match('/(?:Ð|Ñ|Â|â€|Гђ|Г‘|Г‚|Гў)/u', $old) === 1
            || (! self::isValidUtf8($old) && self::isValidUtf8($new));
    }

    private static function replacementChar(): string
    {
        return html_entity_decode('&#65533;', ENT_NOQUOTES, 'UTF-8');
    }
}
