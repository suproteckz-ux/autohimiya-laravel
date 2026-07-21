@php
    $ancestors = collect($category->ancestors());
    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => collect([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => route('home')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Каталог', 'item' => route('catalog.index')],
        ])
            ->merge($ancestors->values()->map(fn ($ancestor, $index) => [
                '@type' => 'ListItem',
                'position' => $index + 3,
                'name' => $ancestor->display_name,
                'item' => route('categories.show', $ancestor->slug),
            ]))
            ->push([
                '@type' => 'ListItem',
                'position' => $ancestors->count() + 3,
                'name' => $category->display_name,
                'item' => route('categories.show', $category->slug),
            ])
            ->values()
            ->all(),
    ];
@endphp
<x-layout.storefront
    :title="\App\Support\StorefrontText::plain($category->meta_title ?: $category->display_name.' - Автохимия.kz')"
    :description="\App\Support\StorefrontText::plain($category->meta_description ?: 'Товары категории '.$category->display_name.' в Алматы.')"
    :canonical="route('categories.show', $category->slug)"
>
    @push('schema')
        <script type="application/ld+json">@json($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endpush

    <section class="catalog-page light-surface">
        <div class="container">
            <div class="breadcrumbs">
                <a href="{{ route('home') }}">Главная</a> / <a href="{{ route('catalog.index') }}">Каталог</a>
                @foreach($ancestors as $ancestor)
                    / <a href="{{ route('categories.show', $ancestor->slug) }}">{{ $ancestor->display_name }}</a>
                @endforeach
                / {{ $category->display_name }}
            </div>

            <div class="catalog-titlebar">
                <div>
                    <h1 class="page-title">{{ $category->display_h1 }}</h1>
                    @if($category->safe_short_description)
                        <div class="category-short-desc">{!! $category->safe_short_description !!}</div>
                    @else
                        <p class="category-short-desc">Товары категории {{ $category->display_name }} с актуальными ценами, фото и наличием.</p>
                    @endif
                </div>
            </div>

            <div class="cat-drawer-overlay" data-cat-drawer-close aria-hidden="true"></div>

            <div class="catalog-layout">
                <aside class="catalog-sidebar">
                    <div class="filter-panel category-menu-panel">
                        <div class="cat-drawer-close-row">
                            <strong>Каталог</strong>
                            <button class="cat-drawer-x" type="button" data-cat-drawer-close aria-label="Закрыть">×</button>
                        </div>
                        <div class="panel-head"><h2>Каталог</h2></div>
                        <div class="category-tree">
                            @foreach($categoryTree as $root)
                                <x-category-tree :category="$root" :current="$category" :show-all="true" />
                            @endforeach
                        </div>
                    </div>
                </aside>

                <section class="catalog-results">
                    <button class="mobile-cat-btn" type="button" data-cat-drawer-open aria-label="Открыть каталог">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                        Каталог
                    </button>
                    @if($subcategories->isNotEmpty())
                        <x-category-wall :categories="$subcategories" :current="$category" title="Подкатегории" />
                    @endif

                    <div class="results-head">
                        <div>
                            <h2>Товары категории</h2>
                            <span>{{ $products->total() }} найдено</span>
                        </div>
                        <form class="inline-sort" action="{{ route('categories.show', $category->slug) }}" method="get">
                            <select name="sort" onchange="this.form.submit()" aria-label="Сортировка">
                                <option value="popular" @selected($sort === 'popular')>Популярные</option>
                                <option value="new" @selected($sort === 'new')>Новинки</option>
                                <option value="price_asc" @selected($sort === 'price_asc')>Цена ↑</option>
                                <option value="price_desc" @selected($sort === 'price_desc')>Цена ↓</option>
                                <option value="name" @selected($sort === 'name')>Название</option>
                            </select>
                        </form>
                    </div>

                    <div class="product-grid">
                        @forelse($products as $product)
                            <x-product-card :product="$product" />
                        @empty
                            <div class="empty-state">
                                <strong>В этой категории пока нет товаров</strong>
                                <span>Напишите нам в WhatsApp, и мы подскажем подходящую замену.</span>
                            </div>
                        @endforelse
                    </div>
                    <div class="pagination">{{ $products->links() }}</div>
                </section>
            </div>

            @if($category->safe_seo_description)
                <div class="category-seo-content">
                    {!! $category->safe_seo_description !!}
                </div>
            @endif
        </div>
    </section>
</x-layout.storefront>
