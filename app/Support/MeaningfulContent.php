<?php

namespace App\Support;

class MeaningfulContent
{
    public static function hasDescription(?string $description): bool
    {
        return self::plainText($description) !== '';
    }

    public static function descriptionIsEmpty(?string $description): bool
    {
        return ! self::hasDescription($description);
    }

    public static function plainText(?string $html): string
    {
        $text = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\p{Z}\x{00A0}]+/u', ' ', $text) ?: '';

        return trim($text);
    }
}
