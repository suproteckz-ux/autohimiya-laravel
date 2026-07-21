<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;

class SiteSettings
{
    /**
     * @param  array<string, mixed>  $fallbacks
     * @return array<string, mixed>
     */
    public static function all(array $fallbacks = []): array
    {
        return Cache::remember('public_site_settings', now()->addMinutes(10), function () use ($fallbacks): array {
            $settings = SiteSetting::query()
                ->where('is_public', true)
                ->pluck('value', 'key')
                ->all();

            return array_replace($fallbacks, $settings);
        });
    }

    public static function get(string $key, ?string $fallback = null): ?string
    {
        $value = self::all(self::defaults())[$key] ?? $fallback;

        return is_scalar($value) ? (string) $value : $fallback;
    }

    public static function phoneHref(?string $phone = null): string
    {
        $phone ??= self::get('company.phone', '+7 701 788 34 63');

        return 'tel:+'.preg_replace('/\D+/', '', $phone);
    }

    public static function whatsappUrl(?string $message = null): string
    {
        $phone = self::get('company.whatsapp', '+7 701 788 34 63');
        $url = 'https://wa.me/'.preg_replace('/\D+/', '', $phone);

        return filled($message) ? $url.'?text='.rawurlencode($message) : $url;
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'company.name' => 'Магазин Автохимия',
            'company.phone' => '+7 701 788 34 63',
            'company.whatsapp' => '+7 701 788 34 63',
            'company.instagram' => 'autohimiki_kz',
            'company.city' => 'Алматы',
            'company.address' => 'Алматы, адрес уточняется',
            'company.work_hours' => 'Пн-Пт 9:00-17:00, Сб 10:00-15:00',
            'storefront.slogan' => 'Нахимичь свою машину',
            'storefront.hero_badge' => 'Более 600 товаров в наличии',
        ];
    }
}
