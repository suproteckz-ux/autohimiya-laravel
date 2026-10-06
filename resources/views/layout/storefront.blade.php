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
    <script type="text/javascript">
        (function(m,e,t,r,i,k,a){
            m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
            m[i].l=1*new Date();
            for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
            k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
        })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id=113470780', 'ym');

        ym(113470780, 'init', {
            ssr:true,
            webvisor:true,
            clickmap:true,
            ecommerce:"dataLayer",
            referrer: document.referrer,
            url: location.href,
            accurateTrackBounce:true,
            trackLinks:true
        });
    </script>
    <style>{!! file_get_contents(resource_path('css/storefront.css')) !!}</style>
</head>
<body>
<noscript>
    <div>
        <img src="https://mc.yandex.ru/watch/113470780"
             style="position:absolute; left:-9999px;"
             alt="" />
    </div>
</noscript>
<x-header />
<main>
    {{ $slot }}
</main>
<x-footer />
@stack('scripts')
</body>
</html>
