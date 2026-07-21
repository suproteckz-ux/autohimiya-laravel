@props(['category'])
@php
    $image = $category->storefront_image_path;
    $count = (int) ($category->products_count ?? 0);
    $word = ($count % 10 === 1 && $count % 100 !== 11) ? 'товар' : ((in_array($count % 10, [2, 3, 4], true) && ! in_array($count % 100, [12, 13, 14], true)) ? 'товара' : 'товаров');
@endphp

<a class="category-card" href="{{ route('categories.show', $category->slug) }}">
    <span class="category-card__main">
        <span class="category-card__content">
            <strong class="category-card__title">{{ $category->display_name }}</strong>
            <small class="category-card__count">{{ $count }} {{ $word }}</small>
            <span class="category-card__arrow" aria-hidden="true">→</span>
        </span>
        <span class="category-thumb {{ $image ? '' : 'category-thumb--empty' }}">
            @if($image)
                <img src="{{ asset('storage/'.$image) }}" alt="{{ $category->display_name }}" loading="lazy" decoding="async">
            @endif
        </span>
    </span>
</a>
