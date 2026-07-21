@php
    $images = $product->images->isNotEmpty()
        ? $product->images->pluck('path')->prepend($product->storefrontOriginalImagePath())->filter()->unique()->values()
        : collect([$product->storefrontOriginalImagePath()])->filter()->values();
    $image = $images->first();
    $category = $product->category ?: $product->categories->first();
    $ancestors = $category ? collect($category->ancestors()) : collect();
    $description = \App\Support\StorefrontText::plain($product->meta_description ?: \App\Support\StorefrontText::excerpt($product->description ?: $product->display_name));
    $sku = \App\Support\StorefrontText::plain($product->sku ?: $product->paloma_sku ?: $product->model);
    $brandName = $product->brand ? \App\Support\StorefrontText::plain($product->brand->name) : '';
    $isAvailable = $product->availability && (int) $product->quantity > 0;
    $productSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->display_name,
        'sku' => $sku,
        'brand' => $brandName !== '' ? ['@type' => 'Brand', 'name' => $brandName] : null,
        'image' => $images->map(fn ($path) => asset('storage/'.$path))->values()->all(),
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'KZT',
            'price' => (float) $product->price,
            'availability' => $isAvailable ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url' => route('products.show', $product->slug),
        ],
    ];
    $breadcrumbItems = [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => route('home')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Каталог', 'item' => route('catalog.index')],
    ];
    if ($category) {
        foreach ($ancestors as $ancestor) {
            $breadcrumbItems[] = ['@type' => 'ListItem', 'position' => count($breadcrumbItems) + 1, 'name' => $ancestor->display_name, 'item' => route('categories.show', $ancestor->slug)];
        }

        $breadcrumbItems[] = ['@type' => 'ListItem', 'position' => count($breadcrumbItems) + 1, 'name' => $category->display_name, 'item' => route('categories.show', $category->slug)];
    }
    $breadcrumbItems[] = ['@type' => 'ListItem', 'position' => count($breadcrumbItems) + 1, 'name' => $product->display_name, 'item' => route('products.show', $product->slug)];
    $breadcrumbSchema = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $breadcrumbItems];
@endphp
<x-layout.storefront
    :title="\App\Support\StorefrontText::plain($product->meta_title ?: $product->display_name.' - купить в Алматы')"
    :description="$description"
    :canonical="route('products.show', $product->slug)"
>
    @push('schema')
        <script type="application/ld+json">@json($productSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
        <script type="application/ld+json">@json($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endpush

    <section class="product-shell light-surface">
        <div class="container">
            <div class="breadcrumbs">
                <a href="{{ route('home') }}">Главная</a> / <a href="{{ route('catalog.index') }}">Каталог</a>
                @foreach($ancestors as $ancestor)
                    / <a href="{{ route('categories.show', $ancestor->slug) }}">{{ $ancestor->display_name }}</a>
                @endforeach
                @if($category)
                    / <a href="{{ route('categories.show', $category->slug) }}">{{ $category->display_name }}</a>
                @endif
                / {{ $product->display_name }}
            </div>

            <article class="product-page">
                <div class="product-gallery">
                    <div class="gallery-main">
                        @if($image)
                            <img id="main-product-image" src="{{ asset('storage/'.$image) }}" alt="{{ $product->display_name }}">
                        @else
                            <span class="product-placeholder"><span>Автохимия</span><small>фото готовится</small></span>
                        @endif
                    </div>
                    @if($images->count() > 1)
                        <div class="thumbs">
                            @foreach($images as $path)
                                <button type="button" onclick="document.getElementById('main-product-image').src='{{ asset('storage/'.$path) }}'">
                                    <img src="{{ asset('storage/'.$path) }}" alt="{{ $product->display_name }}" loading="lazy" decoding="async" width="96" height="96">
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="product-summary">
                    <div class="product-kicker">
                        @if($brandName !== '')<span>{{ $brandName }}</span>@endif
                        @if($category)<span>{{ $category->display_name }}</span>@endif
                    </div>
                    <h1>{{ $product->display_h1 }}</h1>

                    <div class="buybox">
                        <div class="summary-price">{{ number_format((float) $product->price, 0, '.', ' ') }} ₸</div>
                        <div class="summary-stock {{ $isAvailable ? 'is-in-stock' : 'is-low-stock' }}">{{ $isAvailable ? 'В наличии' : 'Уточняйте наличие' }}</div>

                        <dl class="summary-list">
                            @if($brandName !== '')<div><dt>Бренд</dt><dd>{{ $brandName }}</dd></div>@endif
                            <div><dt>Остаток</dt><dd>{{ (int) $product->quantity }}</dd></div>
                            @if($sku)<div><dt>SKU</dt><dd>{{ $sku }}</dd></div>@endif
                        </dl>

                        <div class="product-actions">
                            <a class="button button-whatsapp button-large" href="{{ \App\Support\SiteSettings::whatsappUrl('Здравствуйте! Интересует '.$product->display_name) }}">Написать в WhatsApp</a>
                        </div>
                    </div>

                    <div class="summary-perks">
                        <span>Оригинальная продукция</span>
                        <span>Доставка по Алматы</span>
                        <span>Консультация по подбору</span>
                    </div>
                </div>
            </article>

            @if($product->canShowKaspiCreditButton())
                <section class="content-panel kaspi-product-panel">
                    <div>
                        <h2>Купить в Kaspi</h2>
                        <p>Откройте карточку товара в Kaspi.kz, чтобы оформить покупку или рассрочку.</p>
                    </div>
                    <div class="kaspi-button-wrap">
                        <x-kaspi.credit-button :product="$product" />
                    </div>
                </section>
            @endif

            <div class="product-content-grid">
                <section class="content-panel">
                    <h2>Описание</h2>
                    @if($product->safe_description)
                        <div class="description">{!! $product->safe_description !!}</div>
                    @else
                        <div class="empty-state">
                            <strong>Описание готовится</strong>
                            <span>Уточните детали товара у консультанта в WhatsApp.</span>
                        </div>
                    @endif
                </section>

                @if($product->attributes->isNotEmpty())
                    <section class="content-panel">
                        <h2>Характеристики</h2>
                        <table class="attributes">
                            @foreach($product->attributes as $attribute)
                                @php
                                    $attributeName = \App\Support\StorefrontText::plain($attribute->name);
                                    $attributeValue = \App\Support\StorefrontText::plain($attribute->value);
                                @endphp
                                @continue($attributeName === '' || $attributeValue === '')
                                <tr>
                                    <td>{{ $attributeName }}</td>
                                    <td>{{ $attributeValue }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </section>
                @endif
            </div>

            <x-category-wall :categories="$categoryWall" :current="$category" title="Каталог автохимии" />
        </div>

        @if($related->isNotEmpty())
            <section class="container section-block">
                <div class="section-head"><h2>Похожие товары</h2></div>
                <div class="product-grid">
                    @foreach($related as $item)
                        <x-product-card :product="$item" />
                    @endforeach
                </div>
            </section>
        @endif
    </section>
</x-layout.storefront>
