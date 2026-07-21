<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Support\SiteSettings;
use Illuminate\Contracts\View\View;

class ContactController extends Controller
{
    public function __invoke(): View
    {
        $settings = SiteSettings::all(SiteSettings::defaults());

        return view('storefront.contacts', compact('settings'));
    }
}
