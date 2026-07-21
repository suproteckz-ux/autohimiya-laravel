@props([
    'title' => 'Автохимия.kz',
    'description' => 'Автохимия и автокосметика в Алматы.',
    'canonical' => url()->current(),
    'noindex' => false,
])
@php
    $siteSettings = \App\Support\SiteSettings::all(\App\Support\SiteSettings::defaults());
    $organizationSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $siteSettings['company.name'],
        'url' => url('/'),
        'telephone' => $siteSettings['company.phone'],
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => $siteSettings['company.address'],
            'addressLocality' => $siteSettings['company.city'],
            'addressCountry' => 'KZ',
        ],
    ];
    $websiteSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Автохимия.kz',
        'url' => url('/'),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => route('search.index').'?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ];
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if($noindex)
        <meta name="robots" content="noindex,follow">
    @endif
    <script type="application/ld+json">@json($organizationSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    <script type="application/ld+json">@json($websiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @stack('schema')
    <style>{!! file_get_contents(resource_path('css/storefront.css')) !!}</style>
</head>
<body>
<x-header />
<main>
    {{ $slot }}
</main>
<x-footer />
@stack('scripts')
</body>
</html>
