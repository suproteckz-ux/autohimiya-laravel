<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Seo\SitemapXmlBuilder;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(SitemapXmlBuilder $builder): Response
    {
        return response($builder->build(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
