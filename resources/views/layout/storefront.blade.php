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
    <script>
        (() => {
            const counterId = 113470780;
            const allowedGoalParams = {
                add_to_cart: ['sku', 'product_id', 'price'],
                order_success: ['order_number', 'total', 'city'],
                kaspi_click: ['sku', 'product_id'],
                whatsapp_click: ['source'],
            };

            const sanitizeParams = (goal, params) => Object.fromEntries(
                Object.entries(params || {}).filter(([key, value]) => (
                    allowedGoalParams[goal]?.includes(key)
                    && ['string', 'number'].includes(typeof value)
                    && value !== ''
                )),
            );

            window.reachMetrikaGoal = (goal, params = {}) => {
                if (typeof window.ym !== 'function') {
                    return false;
                }

                try {
                    window.ym(counterId, 'reachGoal', goal, sanitizeParams(goal, params));

                    return true;
                } catch (error) {
                    return false;
                }
            };

            const whatsappSource = (link) => {
                if (link.closest('.mobile-menu')) return 'mobile_menu';
                if (link.closest('.site-header')) return 'header';
                if (link.closest('.product-page')) return 'product';
                if (link.closest('.hero')) return 'hero';
                if (link.closest('.contacts-shell')) return 'contacts';
                if (link.closest('.product-card')) return 'product_card';
                if (link.closest('.site-footer')) return 'footer';

                return 'storefront';
            };

            const kaspiParams = (element) => {
                const container = element.closest('.kaspi-button-wrap');

                return {
                    sku: container?.dataset.metrikaSku,
                    product_id: container?.dataset.metrikaProductId,
                };
            };

            document.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const whatsappLink = target?.closest('a[href*="wa.me/"], a[href*="api.whatsapp.com/"]');

                if (whatsappLink) {
                    window.reachMetrikaGoal('whatsapp_click', { source: whatsappSource(whatsappLink) });

                    return;
                }

                const kaspiControl = target?.closest(
                    'a[href*="kaspi.kz"], .kaspi-button-wrap .ks-widget, .kaspi-button-wrap a, .kaspi-button-wrap button, .kaspi-button-wrap [role="button"]',
                );

                if (kaspiControl) {
                    window.reachMetrikaGoal('kaspi_click', kaspiParams(kaspiControl));
                }
            });

            document.addEventListener('storefront:add-to-cart:success', (event) => {
                window.reachMetrikaGoal('add_to_cart', event.detail);
            });

            document.addEventListener('storefront:order:success', (event) => {
                window.reachMetrikaGoal('order_success', event.detail);
            });
        })();
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
