<?php

namespace App\Support;

class StorefrontCanonicalUrl
{
    public static function base(): string
    {
        $configured = trim((string) config('app.url'));
        $base = $configured !== '' ? $configured : url('/');

        return rtrim($base, '/');
    }

    public static function path(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return self::base().$path;
    }
}