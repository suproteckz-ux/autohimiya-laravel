@props(['categories', 'current' => null, 'title' => null])

@php
    $currentId = $current?->id;
@endphp

@if($categories->isNotEmpty())
    <section class="category-wall-section" data-category-accordion>
        @if($title)
            <div class="section-head">
                <h2>{{ $title }}</h2>
            </div>
        @endif

        <div class="category-wall">
            @foreach($categories as $category)
                @php
                    $children = ($category->children ?? collect())
                        ->filter(fn ($child) => (int) ($child->products_count ?? 0) > 0 && $child->has_human_name)
                        ->take(8)
                        ->values();
                    $count = (int) ($category->products_count ?? 0);
                    $word = ($count % 10 === 1 && $count % 100 !== 11) ? 'товар' : ((in_array($count % 10, [2, 3, 4], true) && ! in_array($count % 100, [12, 13, 14], true)) ? 'товара' : 'товаров');
                    $isActive = $currentId === $category->id;
                    $previewPath = $category->storefront_image_path;
                    $hasChildren = $children->isNotEmpty();
                @endphp
                <article class="category-wall-card {{ $isActive ? 'is-active' : '' }} {{ $hasChildren ? 'has-children' : 'has-no-children' }}" data-category-card>
                    <a class="category-wall-toggle{{ $previewPath ? '' : ' no-img' }}"
                       href="{{ route('categories.show', $category->slug) }}">
                        <span class="category-wall-copy">
                            <strong>{{ $category->display_name }}</strong>
                            <small>{{ $count }} {{ $word }}</small>
                        </span>

                        <span class="category-wall-preview {{ $previewPath ? '' : 'category-wall-preview--empty' }}" aria-hidden="true">
                            @if($previewPath)
                                <img src="{{ asset('storage/'.$previewPath) }}" alt="" loading="lazy" decoding="async">
                            @endif
                        </span>

                        <span class="category-wall-arrow" aria-hidden="true">→</span>
                    </a>

                    <div class="category-wall-footer {{ $hasChildren ? '' : 'category-wall-footer--empty' }}">
                        @if($hasChildren)
                            <button class="category-wall-expand" type="button" aria-expanded="false">
                                <span>Подкатегории</span>
                                <svg viewBox="0 0 16 16" aria-hidden="true" fill="none" width="13" height="13">
                                    <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        @else
                            <span class="category-wall-footer-placeholder" aria-hidden="true">Подкатегории</span>
                        @endif
                    </div>

                    @if($hasChildren)
                        <div class="category-wall-panel" hidden>
                            <div class="cwp-inner">
                                <div class="cwp-chips">
                                    @foreach($children as $child)
                                        <a class="cwp-link{{ $currentId === $child->id ? ' is-active' : '' }}"
                                           href="{{ route('categories.show', $child->slug) }}">{{ $child->display_name }}</a>
                                    @endforeach
                                </div>
                                <a class="cwp-all" href="{{ route('categories.show', $category->slug) }}">Все товары →</a>
                            </div>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    @once
        @push('scripts')
            <script>
                (() => {
                    const initCategoryAccordions = () => {
                        document.querySelectorAll('[data-category-accordion]').forEach((root) => {
                            if (root.dataset.ready === '1') {
                                return;
                            }

                            root.dataset.ready = '1';

                            let openCard  = null;
                            let openPanel = null;

                            function collapse() {
                                if (!openCard || !openPanel) return;
                                openCard.appendChild(openPanel);
                                openPanel.hidden = true;
                                openPanel.removeAttribute('data-detached');
                                openCard.classList.remove('is-expanded');
                                openCard.querySelector('.category-wall-expand')
                                    ?.setAttribute('aria-expanded', 'false');
                                openCard  = null;
                                openPanel = null;
                            }

                            root.addEventListener('click', (event) => {
                                const btn = event.target.closest('.category-wall-expand');
                                if (!btn || !root.contains(btn)) return;

                                const card = btn.closest('[data-category-card]');
                                if (!card) return;

                                // Toggle off if same card
                                if (card === openCard) { collapse(); return; }

                                // Close previous, then open new
                                collapse();

                                const panel = card.querySelector('.category-wall-panel');
                                if (!panel) return;

                                const wall = card.closest('.category-wall');
                                if (!wall) return;

                                // Find the last card in the same grid row by offsetTop
                                const allCards = Array.from(wall.querySelectorAll('[data-category-card]'));
                                const rowTop   = card.offsetTop;
                                const sameRow  = allCards.filter(c => Math.abs(c.offsetTop - rowTop) < 4);
                                const anchor   = sameRow[sameRow.length - 1];

                                panel.dataset.detached = '1';
                                anchor.after(panel);
                                panel.hidden = false;
                                card.classList.add('is-expanded');
                                btn.setAttribute('aria-expanded', 'true');

                                openCard  = card;
                                openPanel = panel;
                            });
                        });
                    };

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', initCategoryAccordions);
                    } else {
                        initCategoryAccordions();
                    }
                })();
            </script>
        @endpush
    @endonce
@endif
