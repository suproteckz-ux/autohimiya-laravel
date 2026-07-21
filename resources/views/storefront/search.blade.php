<x-layout.storefront
    :title="$query ? 'Поиск: '.$query.' - Автохимия.kz' : 'Поиск - Автохимия.kz'"
    description="Поиск по каталогу Автохимия.kz."
    :noindex="true"
>
    <section class="catalog-page light-surface">
        <div class="container">
            <div class="breadcrumbs"><a href="{{ route('home') }}">Главная</a> / Поиск</div>
            <div class="catalog-titlebar">
                <div>
                    <h1 class="page-title">{{ $query ? 'Поиск: '.$query : 'Поиск по каталогу' }}</h1>
                    <p>Найдено товаров: {{ $products->total() }}</p>
                </div>
                <form class="search-page-form" action="{{ route('search.index') }}" method="get">
                    <input name="q" value="{{ $query }}" placeholder="Название, бренд, SKU">
                    <button class="button button-primary" type="submit">Найти</button>
                </form>
            </div>

            <section class="section-block">
                <div class="product-grid">
                    @forelse($products as $product)
                        <x-product-card :product="$product" />
                    @empty
                        <div class="empty-state">
                            <strong>Ничего не найдено</strong>
                            <span>Попробуйте другой запрос или напишите нам в WhatsApp, если нужен подбор по задаче.</span>
                        </div>
                    @endforelse
                </div>
                <div class="pagination">{{ $products->links() }}</div>
            </section>
        </div>
    </section>
</x-layout.storefront>
