@php
    $settings = \App\Support\SiteSettings::all(\App\Support\SiteSettings::defaults());
    $phone = $settings['company.phone'] ?? '';
    $address = $settings['company.address'] ?? '';
    $workHours = $settings['company.work_hours'] ?? '';
    $instagram = ltrim((string) ($settings['company.instagram'] ?? ''), '@');
    $location = $address !== '' && str_starts_with(mb_strtolower($address), 'алматы')
        ? $address
        : trim('Алматы, '.$address, ' ,');
@endphp

<header class="site-header">
    <div class="header-top">
        <div class="container header-top-grid">
            @if($location !== '')
                <span>{{ $location }}</span>
            @endif
            @if($workHours !== '')
                <span>{{ $workHours }}</span>
            @endif
            <span>Доставка по Казахстану</span>
            <span>Консультация специалистов</span>
            @if($instagram !== '')
                <a href="https://www.instagram.com/{{ $instagram }}" target="_blank" rel="noopener">Instagram</a>
            @endif
        </div>
    </div>

    <div class="container header-main">
        <button class="catalog-link header-catalog mobile-menu-toggle" type="button" aria-label="Открыть меню" aria-controls="mobile-menu" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            <span>Меню</span>
        </button>

        <a class="brand-logo" href="{{ route('home') }}" aria-label="Автохимия.kz">
            <img src="{{ asset('assets/autohimiya-logo.jpg') }}" alt="Автохимия.kz - {{ $settings['storefront.slogan'] ?? '' }}" width="260" height="72">
        </a>

        <a class="catalog-link header-catalog desktop-catalog" href="{{ route('catalog.index') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            <span>Каталог</span>
        </a>

        <form class="header-search" action="{{ route('search.index') }}" method="get">
            <label class="sr-only" for="site-search">Поиск</label>
            <input id="site-search" name="q" value="{{ request('q') }}" placeholder="Поиск по товарам, брендам, категориям...">
            <button class="search-submit" type="submit" aria-label="Найти">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            </button>
        </form>

        @if($phone !== '')
            <a class="header-phone" href="{{ \App\Support\SiteSettings::phoneHref($phone) }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.7 19.7 0 0 1-8.6-3.1 19.4 19.4 0 0 1-6-6A19.7 19.7 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2.1Z"/></svg>
                <span>
                    <strong>{{ $phone }}</strong>
                    <small>Написать в WhatsApp</small>
                </span>
            </a>

            <a class="button button-whatsapp header-whatsapp" href="{{ \App\Support\SiteSettings::whatsappUrl('Здравствуйте! Нужна консультация по автохимии.') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.8a8.5 8.5 0 0 1-12.6 7.5L4 20l.8-3.8a8.5 8.5 0 1 1 15.7-4.4Z"/><path d="M8.9 8.6c.2-.4.4-.4.7-.4h.5c.2 0 .4 0 .5.4l.7 1.6c.1.2.1.4-.1.6l-.4.5c-.1.1-.2.3-.1.5.4.8 1 1.5 1.8 2 .2.1.4.1.5-.1l.6-.7c.2-.2.4-.2.6-.1l1.7.8c.2.1.4.3.3.5-.1.7-.6 1.4-1.4 1.5-1 .1-2.4-.3-4.1-1.7-1.5-1.3-2.5-3-2.7-4.1-.1-.5.2-.9.4-1.3Z"/></svg>
                <span>WhatsApp</span>
            </a>
        @endif
    </div>

    <nav class="container header-nav" aria-label="Основное меню">
        <a href="{{ route('home') }}#brands">Бренды</a>
        <a href="{{ route('home') }}#hits">Хиты продаж</a>
        <a href="{{ route('catalog.index', ['q' => 'уход']) }}">Подбор по задаче</a>
        <a href="{{ route('contacts') }}">Контакты</a>
    </nav>

    <div class="mobile-menu-overlay" data-mobile-menu-close></div>
    <aside class="mobile-menu" id="mobile-menu" aria-hidden="true">
        <div class="mobile-menu-head">
            <strong>Меню</strong>
            <button type="button" aria-label="Закрыть меню" data-mobile-menu-close>×</button>
        </div>
        <form class="mobile-menu-search" action="{{ route('search.index') }}" method="get">
            <input name="q" value="{{ request('q') }}" placeholder="Поиск по каталогу">
            <button type="submit">Найти</button>
        </form>
        <nav aria-label="Мобильное меню">
            <a href="{{ route('catalog.index') }}">Каталог</a>
            <a href="{{ route('home') }}#categories">Категории</a>
            <a href="{{ route('home') }}#brands">Бренды</a>
            <a href="{{ route('home') }}#hits">Хиты продаж</a>
            <a href="{{ route('catalog.index', ['q' => 'уход']) }}">Подбор по задаче</a>
            <a href="{{ route('contacts') }}">Контакты</a>
        </nav>
        @if($phone !== '' || $workHours !== '')
            <div class="mobile-menu-contact">
                @if($phone !== '')
                    <a href="{{ \App\Support\SiteSettings::phoneHref($phone) }}">{{ $phone }}</a>
                @endif
                @if($workHours !== '')
                    <small>{{ $workHours }}</small>
                @endif
            </div>
        @endif
        @if($phone !== '')
            <a class="button button-whatsapp" href="{{ \App\Support\SiteSettings::whatsappUrl('Здравствуйте! Нужна консультация по автохимии.') }}">Написать в WhatsApp</a>
        @endif
    </aside>

    <script>
        (() => {
            const initMobileMenu = () => {
                const toggle = document.querySelector('.mobile-menu-toggle');
                const menu = document.querySelector('.mobile-menu');
                const overlay = document.querySelector('.mobile-menu-overlay');
                const closeNodes = document.querySelectorAll('[data-mobile-menu-close], .mobile-menu a');

                if (!toggle || !menu || !overlay || toggle.dataset.ready === '1') {
                    return;
                }

                const open = () => {
                    document.body.classList.add('mobile-menu-open');
                    toggle.setAttribute('aria-expanded', 'true');
                    menu.setAttribute('aria-hidden', 'false');
                };

                const close = () => {
                    document.body.classList.remove('mobile-menu-open');
                    toggle.setAttribute('aria-expanded', 'false');
                    menu.setAttribute('aria-hidden', 'true');
                };

                toggle.dataset.ready = '1';
                toggle.addEventListener('click', open);
                closeNodes.forEach((node) => node.addEventListener('click', close));
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        close();
                    }
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initMobileMenu);
            } else {
                initMobileMenu();
            }
        })();
    </script>
</header>
