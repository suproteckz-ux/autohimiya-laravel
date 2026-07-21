<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

class Utf8Sanitizer
{
    public static function clean(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::cleanString($value);
        }

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                $cleanKey = is_string($key) ? (self::cleanString($key) ?? $key) : $key;
                $clean[$cleanKey] = self::clean($item);
            }

            return $clean;
        }

        if ($value instanceof Arrayable) {
            return self::clean($value->toArray());
        }

        if ($value instanceof JsonSerializable) {
            return self::clean($value->jsonSerialize());
        }

        if ($value instanceof Stringable) {
            return self::cleanString((string) $value);
        }

        if (is_object($value)) {
            return self::clean(get_object_vars($value));
        }

        return $value;
    }

    public static function cleanString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = TextEncoding::clean($value) ?? '';
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\0", '', $value);

        if (! mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1251, ISO-8859-1');

            if (is_string($converted)) {
                $value = $converted;
            }
        }

        if (function_exists('iconv')) {
            $iconv = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

            if (is_string($iconv)) {
                $value = $iconv;
            }
        } else {
            $value = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xC2-\xF4][^\x80-\xBF]*/', '', $value) ?? $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            $value = mb_scrub($value, 'UTF-8');
        }

        $value = self::repairMojibake($value);
        $value = self::removeBrokenText($value);

        return trim($value);
    }

    /**
     * Sanitize a string for safe storage in a MySQL VARCHAR/TEXT column.
     *
     * Handles invalid UTF-8, replacement chars, 4-byte emoji (MySQL utf8 ≠ utf8mb4),
     * control characters, and truncates to the given column length.
     */
    public static function forDb(?string $value, int $maxLength = 255): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = self::cleanString($value) ?? '';

        // Remove 4-byte characters (emoji, supplementary planes) — MySQL utf8 only handles BMP
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value) ?? $value;

        // Remove C0/C1 control characters except TAB (09), LF (0A), CR (0D)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        // Collapse runs of whitespace (preserves single spaces)
        $value = preg_replace('/[^\S\n]+/u', ' ', $value) ?? $value;

        $value = trim($value);

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    public static function hasBrokenText(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return str_contains($value, "\u{FFFD}")
            || preg_match('/(?:Р[�A-Za-z]|С[�A-Za-z]|Ð|Ñ|Â|�)/u', $value) === 1
            || preg_match('/\s{2,}/u', $value) === 1;
    }

    private static function removeBrokenText(string $value): string
    {
        // Remove Unicode replacement character and common mojibake markers.
        $value = str_replace(["\u{FFFD}", 'ï¿½'], '', $value);

        // Remove isolated mojibake bytes that often appear after broken cp1251/utf8 conversions.
        $value = preg_replace('/\b(?:Ð|Ñ|Â|â€™|â€œ|â€\x9d|â€“|â€”)\b/u', '', $value) ?? $value;

        // Collapse whitespace and clean punctuation gaps.
        $value = preg_replace('/[ \t\r\n]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+([,.;:!?])/u', '$1', $value) ?? $value;
        $value = preg_replace('/([,(])\s*([).,;:!?])/u', '$2', $value) ?? $value;

        return trim($value);
    }

    private static function repairMojibake(string $value): string
    {
        if (! preg_match('/(?:Ð|Ñ|Â|â€|â€™|â€œ|â€“|â€”)/u', $value)) {
            return $value;
        }

        $candidates = array_filter([
            @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1'),
            @mb_convert_encoding($value, 'UTF-8', 'Windows-1252'),
        ], 'is_string');

        $best = $value;
        $bestScore = self::textQualityScore($value);

        foreach ($candidates as $candidate) {
            $score = self::textQualityScore($candidate);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private static function textQualityScore(string $value): int
    {
        preg_match_all('/\p{Cyrillic}/u', $value, $cyrillic);
        preg_match_all('/(?:Ð|Ñ|Â|â€|â€™|â€œ|â€“|â€”|�)/u', $value, $mojibake);

        return count($cyrillic[0]) - (count($mojibake[0]) * 4);
    }

    /**
     * Sanitize an exception message for safe storage in a DB error column.
     * Strips invalid bytes, removes SQL dumps, truncates to maxLength.
     */
    public static function errorForDb(\Throwable $e, int $maxLength = 1000): string
    {
        $class = get_class($e);
        $message = $e->getMessage();

        // Strip the embedded SQL statement that PDO exceptions include after "SQL: ..."
        $message = (string) preg_replace('/\s+\(SQL:.*\)$/su', '', $message);

        // Keep only the first line (removes multi-line stack traces that leak via getMessage())
        $lines = explode("\n", $message);
        $message = trim($lines[0]);

        $safe = self::forDb($class.': '.$message, $maxLength);

        return $safe !== '' ? $safe : $class;
    }
}
