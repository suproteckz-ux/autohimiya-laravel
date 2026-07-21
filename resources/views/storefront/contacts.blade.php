<x-layout.storefront
    title="Контакты - Автохимия.kz"
    description="Контакты магазина Автохимия.kz в Алматы: телефон, WhatsApp, Instagram, адрес и график работы."
    :canonical="route('contacts')"
>
    <section class="light-surface contacts-page">
        <div class="container">
            <div class="breadcrumbs"><a href="{{ route('home') }}">Главная</a> / Контакты</div>

            <div class="contacts-shell">
                <div class="contacts-copy">
                    <h1 class="page-title">Контакты</h1>
                    <p>Поможем подобрать автохимию под задачу, сезон и тип автомобиля.</p>
                    <div class="hero-actions">
                        <a class="button button-primary button-large" href="{{ \App\Support\SiteSettings::phoneHref($settings['company.phone']) }}">Позвонить</a>
                        <a class="button button-whatsapp button-large" href="{{ \App\Support\SiteSettings::whatsappUrl('Здравствуйте! Нужна консультация по автохимии.') }}">WhatsApp</a>
                    </div>
                </div>

                <div class="content-panel">
                    <x-contact-info />
                </div>
            </div>
        </div>
    </section>
</x-layout.storefront>
