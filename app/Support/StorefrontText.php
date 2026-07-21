<?php

namespace App\Support;

use Illuminate\Support\Str;

class StorefrontText
{
    public static function plain(?string $value, string $fallback = ''): string
    {
        $rawIssue = TextEncoding::issue($value);
        $value = TextEncoding::clean((string) $value) ?? '';
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?: '';
        $value = trim($value);
        $value = trim($value, " \t\n\r\0\x0B\"'`");

        if ($value === '' || in_array($rawIssue, ['replacement_character', 'repeated_question_marks', 'mojibake'], true) || ! TextEncoding::isDisplaySafe($value)) {
            return $fallback;
        }

        return $value;
    }

    public static function html(?string $value): string
    {
        if (! TextEncoding::isDisplaySafe((string) $value) && TextEncoding::issue($value) !== 'html_entity_artifacts') {
            return '';
        }

        $value = TextEncoding::clean((string) $value) ?? '';
        $value = preg_replace('/<\s*(script|style|iframe|object|embed|link|meta)\b[^>]*>.*?<\s*\/\s*\1\s*>/isu', '', $value) ?: '';
        $value = preg_replace('/<\s*(script|style|iframe|object|embed|link|meta)\b[^>]*\/?\s*>/isu', '', $value) ?: '';
        $value = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/isu', '', $value) ?: '';
        $value = preg_replace('/\sstyle\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/isu', '', $value) ?: '';
        $value = preg_replace('/\s(href|src)\s*=\s*([\'"])\s*javascript:.*?\2/isu', ' $1="#"', $value) ?: '';

        return trim($value);
    }

    public static function hasHumanName(?string $value): bool
    {
        $value = self::plain($value);

        if ($value === '') {
            return false;
        }

        return preg_match('/^(category|brand|product)\s*#?\d+$/iu', $value) !== 1;
    }

    public static function excerpt(?string $value, int $limit = 160): string
    {
        return Str::limit(self::plain($value), $limit);
    }
}
