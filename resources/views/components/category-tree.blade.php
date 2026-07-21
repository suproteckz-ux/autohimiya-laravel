@props(['category', 'depth' => 0, 'showAll' => false, 'current' => null])
@php
    $count = (int)($category->products_count ?? 0);
    $children = $category->children
        ->filter(fn($ch) => $showAll || (int)($ch->products_count ?? 0) > 0)
        ->values();
    $hasChildren = $children->isNotEmpty();
    $isActive = $current?->id === $category->id
        || (request()->routeIs('categories.show') && request()->route('slug') === $category->slug);
    $ancestorIds = $current ? collect($current->ancestors())->pluck('id')->all() : [];
    $isOpen = $isActive || in_array($category->id, $ancestorIds, true);
    $label = $category->display_name;
@endphp

<div class="nav-item{{ $isActive ? ' nav-item--active' : '' }}{{ ($hasChildren && $isOpen) ? ' nav-item--open' : '' }}"
     data-d="{{ (int)$depth }}">
    <div class="nav-row">
        <a class="nav-link" href="{{ route('categories.show', $category->slug) }}" title="{{ $label }}">
            <span class="nav-label">{{ $label }}</span>
            <span class="nav-count">{{ $count }}</span>
        </a>
        @if($hasChildren)
            <button class="nav-chevron" type="button"
                    aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                    aria-label="{{ $isOpen ? 'Свернуть' : 'Развернуть' }}">
                <svg viewBox="0 0 16 16" aria-hidden="true" fill="none">
                    <path d="M6 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        @endif
    </div>
    @if($hasChildren)
        <div class="nav-children-wrap">
            <div class="nav-children">
                @foreach($children as $child)
                    <x-category-tree
                        :category="$child"
                        :depth="$depth + 1"
                        :show-all="$showAll"
                        :current="$current"
                    />
                @endforeach
            </div>
        </div>
    @endif
</div>

@once
    @push('scripts')
        <script>
            (() => {
                const initCatNav = () => {
                    document.querySelectorAll('.category-tree').forEach(tree => {
                        if (tree.dataset.navReady) return;
                        tree.dataset.navReady = '1';
                        tree.addEventListener('click', e => {
                            // Chevron: toggle accordion only — do NOT navigate
                            const btn = e.target.closest('.nav-chevron');
                            if (btn && tree.contains(btn)) {
                                e.preventDefault();
                                e.stopPropagation();
                                const item = btn.closest('.nav-item');
                                if (!item) return;
                                const open = item.classList.toggle('nav-item--open');
                                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                                return;
                            }
                            // Link (.nav-link): default browser navigation fires — no interception
                        });
                    });

                    document.addEventListener('click', e => {
                        if (e.target.closest('[data-cat-drawer-open]')) {
                            document.body.classList.add('cat-drawer-open');
                            document.body.style.overflow = 'hidden';
                        }
                        if (e.target.closest('[data-cat-drawer-close]')) {
                            document.body.classList.remove('cat-drawer-open');
                            document.body.style.overflow = '';
                        }
                    });
                };

                document.readyState === 'loading'
                    ? document.addEventListener('DOMContentLoaded', initCatNav)
                    : initCatNav();
            })();
        </script>
    @endpush
@endonce
