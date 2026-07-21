<?php

namespace App\Services\CatalogRecovery;

class OpenCartImageResolver
{
    public function __construct(private readonly ?string $projectRoot = null)
    {
    }

    public function resolve(?string $path): ?string
    {
        foreach ($this->candidates($path) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function candidates(?string $path): array
    {
        $path = trim((string) $path);

        if ($path === '' || str_contains(strtolower($path), 'image/cache')) {
            return [];
        }

        $root = trim((string) ($this->projectRoot ?: config('services.opencart.project_root', base_path('..'))), "\"'");

        if ($root === '' || ! is_dir($root)) {
            return [];
        }

        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, ltrim($path, "\\/"));
        $withoutImage = preg_replace('#^image[\\\\/]#i', '', $normalized) ?: $normalized;
        $basename = basename($withoutImage);

        $candidates = [
            $root.DIRECTORY_SEPARATOR.$normalized,
            $root.DIRECTORY_SEPARATOR.'image'.DIRECTORY_SEPARATOR.$withoutImage,
            $root.DIRECTORY_SEPARATOR.'image'.DIRECTORY_SEPARATOR.'catalog'.DIRECTORY_SEPARATOR.$withoutImage,
            $root.DIRECTORY_SEPARATOR.'image'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.$withoutImage,
            $root.DIRECTORY_SEPARATOR.'image'.DIRECTORY_SEPARATOR.'catalog'.DIRECTORY_SEPARATOR.$basename,
            $root.DIRECTORY_SEPARATOR.'image'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.$basename,
        ];

        if (str_starts_with(str_replace('\\', '/', $withoutImage), 'catalog/')) {
            $candidates[] = $root.DIRECTORY_SEPARATOR.'image'.DIRECTORY_SEPARATOR.$withoutImage;
            $candidates[] = $root.DIRECTORY_SEPARATOR.$withoutImage;
        }

        if (str_starts_with(str_replace('\\', '/', $withoutImage), 'data/')) {
            $candidates[] = $root.DIRECTORY_SEPARATOR.'image'.DIRECTORY_SEPARATOR.$withoutImage;
            $candidates[] = $root.DIRECTORY_SEPARATOR.$withoutImage;
        }

        return array_values(array_unique($candidates));
    }
}
