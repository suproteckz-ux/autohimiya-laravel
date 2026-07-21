<?php

namespace App\Support;

use App\Models\Brand;

class AdminBrandOptions
{
    public static function active(): array
    {
        return Brand::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(function (Brand $brand): array {
                $label = TextEncoding::preview(StorefrontText::plain(TextEncoding::clean($brand->name), 'Brand '.$brand->id), 90);

                return [$brand->id => $label !== '[empty]' ? $label : 'Brand '.$brand->id];
            })
            ->all();
    }
}
