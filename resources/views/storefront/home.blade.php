<x-layout.storefront
    title="Автохимия и автокосметика в Алматы - Автохимия.kz"
    description="Интернет-магазин автохимии и автокосметики в Алматы: средства для двигателя, кузова, салона, стекол, шин и регулярного ухода."
>
    <section class="hero">
        <div class="container hero-grid">
            <div class="hero-copy">
                <p class="hero-badge">{{ \App\Support\SiteSettings::get('storefront.hero_badge', 'Более 600 товаров в наличии') }}</p>
                <h1>Автохимия и аксессуары <span>для ухода за автомобилем</span></h1>
                <p class="hero-script">{{ \App\Support\SiteSettings::get('storefront.slogan', 'Нахимичь свою машину') }}</p>

                <div class="hero-perks">
                    <span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-5"/></svg>
                        <strong>Только оригинальная продукция</strong>
                    </span>
                    <span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h11v10H3z"/><path d="M14 10h4l3 3v4h-7z"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/></svg>
                        <strong>Доставка по Казахстану</strong>
                    </span>
                    <span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 0 1 16 0"/><path d="M4 12v4a2 2 0 0 0 2 2h1v-6H4Z"/><path d="M20 12v4a2 2 0 0 1-2 2h-1v-6h3Z"/><path d="M13 20h3"/></svg>
                        <strong>Консультация специалистов</strong>
                    </span>
                </div>

                <div class="hero-actions">
                    <a class="button button-primary button-large" href="{{ route('catalog.index') }}">
                        <span>Перейти в каталог</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
                    </a>
                    <a class="button button-whatsapp-outline button-large" href="{{ \App\Support\SiteSettings::whatsappUrl('Здравствуйте! Нужна консультация по автохимии.') }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.8a8.5 8.5 0 0 1-12.6 7.5L4 20l.8-3.8a8.5 8.5 0 1 1 15.7-4.4Z"/><path d="M8.9 8.6c.2-.4.4-.4.7-.4h.5c.2 0 .4 0 .5.4l.7 1.6c.1.2.1.4-.1.6l-.4.5c-.1.1-.2.3-.1.5.4.8 1 1.5 1.8 2 .2.1.4.1.5-.1l.6-.7c.2-.2.4-.2.6-.1l1.7.8c.2.1.4.3.3.5-.1.7-.6 1.4-1.4 1.5-1 .1-2.4-.3-4.1-1.7-1.5-1.3-2.5-3-2.7-4.1-.1-.5.2-.9.4-1.3Z"/></svg>
                        <span>Написать в WhatsApp</span>
                    </a>
                </div>
            </div>

            <div class="hero-stage" aria-label="Популярная автохимия">
                <div class="hero-splash" aria-hidden="true"></div>
                <div class="hero-composition">
                    <img src="{{ asset('assets/hero-premium-car-products-clean.png') }}" alt="Автохимия и аксессуары для ухода за автомобилем" loading="eager" decoding="async">
                </div>
            </div>
        </div>
    </section>

    <section class="light-surface">
        <div class="container">
            <div id="categories">
                <div class="section-head">
                    <h2>Популярные категории</h2>
                    <a class="section-link" href="{{ route('catalog.index') }}">Все категории</a>
                </div>
                <x-category-wall :categories="$categories->take(12)" />
            </div>
        </div>

        <div class="container home-catalog">
            <div class="home-products">
                <section class="section-block">
                    <div class="section-head">
                        <h2>Новинки</h2>
                        <a class="section-link" href="{{ route('catalog.index', ['sort' => 'popular']) }}">Смотреть все</a>
                    </div>
                    <div class="product-grid">
                        @forelse($newProducts->take(6) as $product)
                            <x-product-card :product="$product" />
                        @empty
                            <div class="empty-state">Товары скоро появятся.</div>
                        @endforelse
                    </div>
                </section>

                <section class="section-block" id="hits">
                    <div class="section-head">
                        <h2>Хиты продаж</h2>
                        <a class="section-link" href="{{ route('catalog.index') }}">Смотреть все</a>
                    </div>
                    <div class="product-grid">
                        @forelse($featuredProducts->take(6) as $product)
                            <x-product-card :product="$product" />
                        @empty
                            <div class="empty-state">Хиты продаж скоро появятся.</div>
                        @endforelse
                    </div>
                </section>

            </div>
        </div>

        <section class="container trust-strip">
            <div><strong>Только оригинальная продукция</strong><span>работаем с проверенными брендами</span></div>
            <div><strong>Быстрая доставка</strong><span>по Алматы и Казахстану</span></div>
            <div><strong>Экспертная поддержка</strong><span>поможем подобрать средство</span></div>
            <div><strong>Удобная оплата</strong><span>наличные, карта, Kaspi Red</span></div>
        </section>

        <section class="container section-block" id="brands">
            <div class="section-head">
                <h2>Популярные бренды</h2>
                <a class="section-link" href="{{ route('catalog.index') }}">Все бренды</a>
            </div>
            <div class="brand-row">
                @forelse($brands->take(8) as $brand)
                    <x-brand-card :brand="$brand" />
                @empty
                    <div class="empty-state">Бренды скоро появятся.</div>
                @endforelse
            </div>
        </section>
    </section>
</x-layout.storefront>
