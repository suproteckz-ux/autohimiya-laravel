<?php

namespace App\Support;

class EncodingIssueDetector
{
    private const MOJIBAKE_PATTERNS = [
        '/Р [А-Яа-яA-Za-z0-9]/u',
        '/РЎ[А-Яа-яA-Za-z0-9]/u',
        '/Р’/u',
        '/Рќ/u',
        '/Рґ/u',
        '/вЂ/u',
        '/пїЅ/u',
        '/�/u',
    ];

    public static function hasEncodingIssue(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            return true;
        }

        foreach (self::MOJIBAKE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function findIssues(mixed $payload, string $path = ''): array
    {
        $issues = [];

        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;
                $issues = array_merge($issues, self::findIssues($value, $childPath));
            }

            return $issues;
        }

        if (is_object($payload)) {
            return self::findIssues(get_object_vars($payload), $path);
        }

        if (is_string($payload) && self::hasEncodingIssue($payload)) {
            $issues[] = [
                'path' => $path,
                'value' => mb_substr($payload, 0, 160),
            ];
        }

        return $issues;
    }
}
