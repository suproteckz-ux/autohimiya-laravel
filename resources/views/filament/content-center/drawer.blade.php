@php
    use App\Models\ContentChangeLog;
    use App\Support\ContentScore;
    use App\Support\ProductImageUrlResolver;

    $url = ProductImageUrlResolver::productAdminUrl($record);
    $score = ContentScore::score($record);
    $problems = ContentScore::problems($record);
    $tasks = $record->enrichmentTasks->sortByDesc('updated_at');
    $imageTasks = $tasks->where('task_type', 'image');
    $descriptionTask = $tasks->firstWhere('task_type', 'description');
    $seoTasks = $tasks->whereIn('task_type', ['seo', 'seo_title', 'seo_description']);
    $logs = ContentChangeLog::query()->where('product_id', $record->id)->latest('created_at')->limit(10)->get();
@endphp

<div style="display:grid;gap:18px;">
    <div style="display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid #e5e7eb;padding-bottom:10px;">
        @foreach(['Обзор', 'Фото', 'Описание', 'SEO', 'История'] as $tab)
            <span style="border-radius:999px;padding:6px 10px;background:#f8fafc;color:#334155;font-size:12px;font-weight:800;border:1px solid #e5e7eb;">{{ $tab }}</span>
        @endforeach
    </div>

    <section style="display:grid;gap:14px;">
        <h3 style="margin:0;font-size:16px;font-weight:900;color:#0f172a;">Обзор</h3>
        <div style="display:grid;grid-template-columns:120px 1fr;gap:16px;align-items:start;">
            <div style="height:120px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                @if($url)
                    <img src="{{ $url }}" alt="{{ $record->display_name }}" style="max-width:100px;max-height:100px;object-fit:contain;">
                @else
                    <span style="font-size:12px;color:#94a3b8;font-weight:800;">Нет фото</span>
                @endif
            </div>
            <div>
                <h2 style="margin:0;font-size:20px;font-weight:900;color:#0f172a;">{{ $record->display_name }}</h2>
                <div style="margin-top:4px;color:#64748b;">SKU: {{ $record->paloma_sku ?: $record->sku ?: $record->model ?: 'не указан' }}</div>
                <div style="margin-top:12px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;font-size:13px;">
                    <div><strong>Категория:</strong> {{ $record->category?->display_name ?: 'Нет категории' }}</div>
                    <div><strong>Бренд:</strong> {{ $record->brand?->display_name ?: 'Нет бренда' }}</div>
                    <div><strong>Цена:</strong> {{ number_format((float) $record->price, 0, '.', ' ') }} KZT</div>
                    <div><strong>Остаток:</strong> {{ (int) $record->quantity }} шт.</div>
                    <div><strong>Обновлен:</strong> {{ $record->updated_at?->format('d.m.Y H:i') }}</div>
                </div>
            </div>
        </div>

        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fff;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <div>
                    <div style="font-weight:900;color:#0f172a;">Content Score</div>
                    <div style="color:#64748b;font-size:13px;">Заполнено {{ ContentScore::filledCount($record) }} из 5 параметров</div>
                </div>
                <div style="font-size:34px;font-weight:900;color:{{ $score >= 80 ? '#16a34a' : ($score >= 60 ? '#f59e0b' : '#ef4444') }};">{{ $score }}%</div>
            </div>
            <div style="margin-top:10px;height:8px;border-radius:999px;background:#e5e7eb;overflow:hidden;">
                <div style="height:100%;width:{{ $score }}%;background:{{ $score >= 80 ? '#16a34a' : ($score >= 60 ? '#f59e0b' : '#ef4444') }};"></div>
            </div>
        </div>

        <div>
            <h4 style="margin:0 0 8px;font-weight:900;">Проблемы</h4>
            @if(empty($problems))
                <div style="color:#16a34a;font-weight:800;">Проблем не найдено</div>
            @else
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    @foreach($problems as $problem)
                        <span style="border-radius:999px;padding:5px 9px;background:#fee2e2;color:#dc2626;font-size:12px;font-weight:800;">{{ $problem }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section style="border-top:1px solid #e5e7eb;padding-top:16px;display:grid;gap:10px;">
        <h3 style="margin:0;font-size:16px;font-weight:900;color:#0f172a;">Фото</h3>
        <div style="display:grid;gap:8px;">
            <div><strong>Текущее фото:</strong> {{ $record->primary_image ?: 'не задано' }}</div>
            @forelse($imageTasks as $task)
                <div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fff;">
                    <div style="font-weight:800;">{{ $task->status }} · confidence {{ $task->confidence }}%</div>
                    <div style="color:#64748b;font-size:12px;">{{ $task->reason }}</div>
                    <div style="margin-top:6px;font-size:12px;word-break:break-all;">{{ $task->suggested_payload['images'][0]['path'] ?? $task->suggested_value ?? 'Предложений пока нет' }}</div>
                </div>
            @empty
                <div style="color:#64748b;">Предложений фото пока нет.</div>
            @endforelse
        </div>
    </section>

    <section style="border-top:1px solid #e5e7eb;padding-top:16px;display:grid;gap:10px;">
        <h3 style="margin:0;font-size:16px;font-weight:900;color:#0f172a;">Описание</h3>
        <div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fff;max-height:150px;overflow:auto;">
            {!! $record->safe_description ?: '<span style="color:#64748b;">Описание отсутствует.</span>' !!}
        </div>
        <div style="border:1px dashed #cbd5e1;border-radius:10px;padding:10px;background:#f8fafc;">
            <strong>Draft:</strong>
            <div style="margin-top:6px;color:#475569;">{{ $descriptionTask?->suggested_payload['description'] ?? $descriptionTask?->suggested_value ?? 'Черновика пока нет.' }}</div>
        </div>
    </section>

    <section style="border-top:1px solid #e5e7eb;padding-top:16px;display:grid;gap:10px;">
        <h3 style="margin:0;font-size:16px;font-weight:900;color:#0f172a;">SEO</h3>
        <div style="display:grid;gap:8px;font-size:13px;">
            <div><strong>Meta Title:</strong> {{ $record->meta_title ?: 'не задан' }}</div>
            <div><strong>Meta Description:</strong> {{ $record->meta_description ?: 'не задан' }}</div>
        </div>
        <div style="display:grid;gap:6px;">
            @forelse($seoTasks as $task)
                <div style="border:1px dashed #cbd5e1;border-radius:10px;padding:10px;background:#f8fafc;">
                    <strong>{{ $task->task_type }} · {{ $task->status }}</strong>
                    <div style="margin-top:4px;color:#475569;">{{ $task->suggested_value ?: 'Черновик пока не заполнен.' }}</div>
                </div>
            @empty
                <div style="color:#64748b;">SEO-черновиков пока нет.</div>
            @endforelse
        </div>
    </section>

    <section style="border-top:1px solid #e5e7eb;padding-top:16px;display:grid;gap:10px;">
        <h3 style="margin:0;font-size:16px;font-weight:900;color:#0f172a;">История</h3>
        @forelse($logs as $log)
            <div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fff;">
                <div style="font-weight:800;">{{ $log->created_at?->format('d.m.Y H:i') }} · {{ $log->type }}</div>
                <div style="font-size:12px;color:#64748b;">Задача #{{ $log->enrichment_task_id ?: 'manual' }}</div>
            </div>
        @empty
            <div style="border:1px dashed #cbd5e1;border-radius:10px;padding:14px;background:#f8fafc;color:#64748b;">Истории изменений пока нет.</div>
        @endforelse
    </section>
</div>
