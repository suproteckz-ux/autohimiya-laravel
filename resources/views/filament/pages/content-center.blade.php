<x-filament-panels::page>
    @php($stats = $this->stats())
    @php($selectedProduct = $this->selectedProduct())

    <div style="display:grid;gap:18px;">
        <div>
            <div style="font-size:13px;color:#64748b;margin-bottom:6px;">Контент-центр > Все товары</div>
            <h1 style="margin:0;font-size:28px;font-weight:800;color:#0f172a;">Контент-центр</h1>
            <p style="margin:6px 0 0;color:#64748b;">Единое рабочее окно наполнения каталога: задачи, draft, approve и publish без перехода в техническую очередь.</p>
        </div>

        <div style="border:1px solid #dbe3ef;border-radius:14px;background:linear-gradient(135deg,#ffffff,#f8fbff);padding:14px 16px;box-shadow:0 8px 20px rgba(15,23,42,.04);">
            <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:18px;flex-wrap:wrap;">
                <div>
                    <div style="font-size:13px;font-weight:800;color:#2563eb;text-transform:uppercase;letter-spacing:.04em;">Общий прогресс</div>
                    <div style="margin-top:6px;font-size:24px;font-weight:900;color:#0f172a;">Каталог заполнен на {{ $stats['average'] }}%</div>
                    <div style="margin-top:4px;color:#64748b;">Средний Content Score по {{ $stats['total'] }} товарам. Работайте с выбранным товаром в правой панели.</div>
                </div>
                <div style="min-width:190px;text-align:right;">
                    <div style="font-size:13px;color:#64748b;">Требует внимания</div>
                    <div style="font-size:30px;font-weight:900;color:#ef4444;">{{ $stats['attention'] }}</div>
                </div>
            </div>
            <div style="margin-top:14px;height:12px;border-radius:999px;background:#e5e7eb;overflow:hidden;">
                <div style="height:100%;width:{{ $stats['average'] }}%;background:linear-gradient(90deg,#2563eb,#22c55e);"></div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;">
            @foreach([
                ['label' => 'Всего товаров', 'value' => $stats['total'], 'sub' => '100%', 'color' => '#2563eb'],
                ['label' => 'С фото', 'value' => $stats['withPhoto'], 'sub' => $stats['total'] ? round($stats['withPhoto'] / $stats['total'] * 100).'%' : '0%', 'color' => '#0ea5e9'],
                ['label' => 'С описанием', 'value' => $stats['withDescription'], 'sub' => $stats['total'] ? round($stats['withDescription'] / $stats['total'] * 100).'%' : '0%', 'color' => '#16a34a'],
                ['label' => 'С SEO', 'value' => $stats['withSeo'], 'sub' => $stats['total'] ? round($stats['withSeo'] / $stats['total'] * 100).'%' : '0%', 'color' => '#8b5cf6'],
                ['label' => 'С брендом', 'value' => $stats['withBrand'], 'sub' => $stats['total'] ? round($stats['withBrand'] / $stats['total'] * 100).'%' : '0%', 'color' => '#f97316'],
                ['label' => 'С категорией', 'value' => $stats['withCategory'], 'sub' => $stats['total'] ? round($stats['withCategory'] / $stats['total'] * 100).'%' : '0%', 'color' => '#22c55e'],
            ] as $card)
                <div style="border:1px solid #e5e7eb;border-radius:11px;background:#fff;padding:10px 12px;box-shadow:0 4px 12px rgba(15,23,42,.035);">
                    <div style="font-size:12px;font-weight:700;color:#334155;">{{ $card['label'] }}</div>
                    <div style="margin-top:6px;font-size:21px;font-weight:900;color:#0f172a;">{{ $card['value'] }}</div>
                    <div style="margin-top:6px;height:6px;border-radius:999px;background:#e5e7eb;overflow:hidden;">
                        <div style="height:100%;width:{{ $card['sub'] }};background:{{ $card['color'] }};"></div>
                    </div>
                    <div style="margin-top:6px;font-size:12px;color:#64748b;">{{ $card['sub'] }}</div>
                </div>
            @endforeach
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;border:1px solid #e5e7eb;border-radius:12px;background:#fff;padding:8px;">
            @foreach($this->tabs() as $key => $tab)
                <button
                    type="button"
                    wire:click="setContentTab('{{ $key }}')"
                    style="
                        border:0;
                        border-radius:9px;
                        padding:8px 11px;
                        cursor:pointer;
                        font-size:13px;
                        font-weight:700;
                        color:{{ $activeContentTab === $key ? '#1d4ed8' : '#475569' }};
                        background:{{ $activeContentTab === $key ? '#eff6ff' : '#fff' }};
                    "
                >
                    {{ $tab['label'] }}
                    <span style="margin-left:6px;border-radius:999px;padding:2px 6px;background:{{ $activeContentTab === $key ? '#dbeafe' : '#f1f5f9' }};font-size:11px;">{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </div>

        <div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px;align-items:start;">
            <div style="min-width:0;">
                {{ $this->table }}
            </div>

            <div style="position:sticky;top:16px;max-height:calc(100vh - 32px);overflow:auto;">
                <details open style="display:block;">
                    <summary style="position:sticky;top:0;z-index:2;list-style:none;cursor:pointer;border:1px solid #dbe3ef;border-radius:12px;background:#fff;padding:10px 12px;margin-bottom:8px;font-size:13px;font-weight:900;color:#0f172a;box-shadow:0 6px 16px rgba(15,23,42,.05);">
                        Панель товара
                    </summary>
                    @include('filament.content-center.product-work-panel', ['product' => $selectedProduct])
                </details>
            </div>
        </div>
    </div>
</x-filament-panels::page>
