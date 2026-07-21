<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        return response(
            "User-agent: *\n".
            "Disallow: /admin\n".
            "Disallow: /search\n".
            'Sitemap: '.url('/sitemap.xml')."\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
