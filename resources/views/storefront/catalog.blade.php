<x-layout.storefront
    title="Каталог автохимии - Автохимия.kz"
    description="Каталог автохимии и автокосметики в Алматы: уход за кузовом, салоном, стеклами, двигателем, топливной системой и другие категории."
>
    <section class="catalog-page light-surface">
        <div class="container">
            <div class="breadcrumbs"><a href="{{ route('home') }}">Главная</a> / Каталог</div>

            <div class="catalog-index-hero">
                <h1 class="page-title">Каталог автохимии</h1>
                <p>Выберите раздел автохимии: уход за кузовом, салоном, стеклами, двигателем, топливной системой и другие категории.</p>
            </div>

            <x-category-wall :categories="$categoryTree" title="Разделы каталога" />

            @if($popularProducts->isNotEmpty())
                <section class="section-block">
                    <div class="section-head">
                        <h2>Популярные товары</h2>
                        <a class="section-link" href="{{ route('search.index') }}">Смотреть все товары</a>
                    </div>
                    <div class="product-grid">
                        @foreach($popularProducts->take(8) as $product)
                            <x-product-card :product="$product" />
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </section>
</x-layout.storefront>
