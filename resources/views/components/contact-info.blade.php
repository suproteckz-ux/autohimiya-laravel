@php
    $settings = \App\Support\SiteSettings::all(\App\Support\SiteSettings::defaults());
    $phone = $settings['company.phone'];
    $instagram = $settings['company.instagram'];
@endphp

<div class="contact-info">
    <div>
        <span>Телефон</span>
        <a href="{{ \App\Support\SiteSettings::phoneHref($phone) }}">{{ $phone }}</a>
    </div>
    <div>
        <span>WhatsApp</span>
        <a href="{{ \App\Support\SiteSettings::whatsappUrl('Здравствуйте! Нужна консультация по автохимии.') }}">Написать в WhatsApp</a>
    </div>
    <div>
        <span>Instagram</span>
        <a href="https://www.instagram.com/{{ ltrim($instagram, '@') }}" target="_blank" rel="noopener">{{ '@'.ltrim($instagram, '@') }}</a>
    </div>
    <div>
        <span>Адрес</span>
        <strong>{{ $settings['company.address'] }}</strong>
    </div>
    <div>
        <span>График</span>
        <strong>{{ $settings['company.work_hours'] }}</strong>
    </div>
</div>
