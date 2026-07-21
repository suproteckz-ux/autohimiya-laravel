<x-layout.storefront
    title="404 - Страница не найдена - Автохимия.kz"
    description="Страница не найдена. Перейдите на главную или в каталог Автохимия.kz."
    noindex="true"
>
    <section class="light-surface error-page">
        <div class="container error-shell">
            <div class="error-card">
                <p class="hero-badge">Автохимия.kz</p>
                <h1>404</h1>
                <p class="error-title">Страница не найдена</p>
                <p class="error-text">Возможно, адрес изменился или товар был снят с публикации. Вы можете вернуться на главную или открыть каталог.</p>
                <div class="hero-actions">
                    <a class="button button-primary button-large" href="{{ route('home') }}">На главную</a>
                    <a class="button button-outline button-large" href="{{ route('catalog.index') }}">В каталог</a>
                </div>
            </div>
        </div>
    </section>
</x-layout.storefront>
