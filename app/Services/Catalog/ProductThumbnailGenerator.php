<?php

namespace App\Services\Catalog;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

class ProductThumbnailGenerator
{
    public const SIZE = 600;

    public function make(ProductImage $image, bool $apply = false): array
    {
        $source = $this->resolveSourcePath((string) $image->path);

        if (! $source || ! is_file($source)) {
            return [
                'status' => 'missing_source',
                'source_path' => $source,
                'thumb_path' => $this->thumbPath($image),
                'error' => 'Source image was not found.',
            ];
        }

        $info = @getimagesize($source);
        if (! $info) {
            return [
                'status' => 'broken_source',
                'source_path' => $source,
                'thumb_path' => $this->thumbPath($image),
                'error' => 'Source image is not readable.',
            ];
        }

        if (! $apply) {
            return [
                'status' => 'would_create',
                'source_path' => $source,
                'thumb_path' => $this->thumbPath($image),
                'width' => $info[0],
                'height' => $info[1],
            ];
        }

        $sourceImage = $this->createSourceImage($source, (int) $info[2]);
        if (! $sourceImage) {
            return [
                'status' => 'unsupported_source',
                'source_path' => $source,
                'thumb_path' => $this->thumbPath($image),
                'error' => 'Source image format is not supported by GD.',
            ];
        }

        $thumb = imagecreatetruecolor(self::SIZE, self::SIZE);
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        $bounds = $this->contentBounds($sourceImage, $sourceWidth, $sourceHeight);

        $contentWidth = max(1, $bounds['right'] - $bounds['left'] + 1);
        $contentHeight = max(1, $bounds['bottom'] - $bounds['top'] + 1);
        $targetMax = (int) round(self::SIZE * 0.86);
        $scale = min($targetMax / $contentWidth, $targetMax / $contentHeight);
        $targetWidth = max(1, (int) round($contentWidth * $scale));
        $targetHeight = max(1, (int) round($contentHeight * $scale));
        $targetX = (int) floor((self::SIZE - $targetWidth) / 2);
        $targetY = (int) floor((self::SIZE - $targetHeight) / 2);

        imagecopyresampled(
            $thumb,
            $sourceImage,
            $targetX,
            $targetY,
            $bounds['left'],
            $bounds['top'],
            $targetWidth,
            $targetHeight,
            $contentWidth,
            $contentHeight,
        );

        $thumbPath = $this->thumbPath($image);
        $fullThumbPath = Storage::disk('public')->path($thumbPath);
        $directory = dirname($fullThumbPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $saved = imagewebp($thumb, $fullThumbPath, 88);
        imagedestroy($sourceImage);
        imagedestroy($thumb);

        if (! $saved) {
            return [
                'status' => 'write_error',
                'source_path' => $source,
                'thumb_path' => $thumbPath,
                'error' => 'Could not write thumbnail file.',
            ];
        }

        $image->forceFill(['card_thumb_path' => $thumbPath])->saveQuietly();

        return [
            'status' => 'created',
            'source_path' => $source,
            'thumb_path' => $thumbPath,
            'width' => $info[0],
            'height' => $info[1],
            'content_width' => $contentWidth,
            'content_height' => $contentHeight,
        ];
    }

    public function thumbPath(ProductImage $image): string
    {
        return 'products/'.$image->product_id.'/thumbs/'.$image->id.'_card.webp';
    }

    public function resolveSourcePath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            return null;
        }

        $candidates = [
            Storage::disk('public')->path($normalized),
            public_path('storage/'.$normalized),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    public function hasLargePadding(string $sourcePath): ?bool
    {
        $info = @getimagesize($sourcePath);
        if (! $info) {
            return null;
        }

        $sourceImage = $this->createSourceImage($sourcePath, (int) $info[2]);
        if (! $sourceImage) {
            return null;
        }

        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        $bounds = $this->contentBounds($sourceImage, $width, $height);
        imagedestroy($sourceImage);

        $contentWidth = max(1, $bounds['right'] - $bounds['left'] + 1);
        $contentHeight = max(1, $bounds['bottom'] - $bounds['top'] + 1);
        $contentArea = $contentWidth * $contentHeight;
        $fullArea = max(1, $width * $height);

        return ($contentArea / $fullArea) < 0.62;
    }

    private function createSourceImage(string $path, int $type): mixed
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function contentBounds($image, int $width, int $height): array
    {
        $threshold = 244;
        $left = $width - 1;
        $right = 0;
        $top = $height - 1;
        $bottom = 0;
        $found = false;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                if ($r < $threshold || $g < $threshold || $b < $threshold) {
                    $found = true;
                    $left = min($left, $x);
                    $right = max($right, $x);
                    $top = min($top, $y);
                    $bottom = max($bottom, $y);
                }
            }
        }

        if (! $found) {
            return ['left' => 0, 'right' => $width - 1, 'top' => 0, 'bottom' => $height - 1];
        }

        $pad = 4;

        return [
            'left' => max(0, $left - $pad),
            'right' => min($width - 1, $right + $pad),
            'top' => max(0, $top - $pad),
            'bottom' => min($height - 1, $bottom + $pad),
        ];
    }
}
