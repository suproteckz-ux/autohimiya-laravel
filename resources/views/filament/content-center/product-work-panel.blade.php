@php
    use App\Models\ContentChangeLog;
    use App\Filament\Resources\ProductResource;
    use App\Support\ContentScore;
    use App\Support\EncodingIssueDetector;
    use App\Support\ProductImageUrlResolver;

    $url = $product ? ProductImageUrlResolver::productAdminUrl($product) : null;
    $score = $product ? ContentScore::score($product) : 0;
    $tasks = $product ? $product->enrichmentTasks->sortByDesc('updated_at') : collect();
    $drafts = $tasks->where('status', 'draft');
    $approved = $tasks->where('status', 'approved');
    $failed = $tasks->where('status', 'failed');
    $lastTask = $tasks->first();
    $lastPublished = $tasks->where('status', 'published')->first();
    $logs = $product ? ContentChangeLog::query()->where('product_id', $product->id)->latest('created_at')->limit(8)->get() : collect();
@endphp

<div style="border:1px solid #dbe3ef;border-radius:16px;background:#fff;box-shadow:0 14px 34px rgba(15,23,42,.08);overflow:hidden;">
    @if(! $product)
        <div style="padding:18px;color:#64748b;">Выберите товар в таблице.</div>
    @else
        <div style="padding:16px;border-bottom:1px solid #e5e7eb;background:#f8fafc;">
            <div style="display:grid;grid-template-columns:76px 1fr;gap:12px;align-items:start;">
                <div style="height:76px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                    @if($url)
                        <img src="{{ $url }}" alt="{{ $product->display_name }}" style="max-width:68px;max-height:68px;object-fit:contain;">
                    @else
                        <span style="font-size:10px;color:#94a3b8;font-weight:800;">Нет фото</span>
                    @endif
                </div>
                <div style="min-width:0;">
                    <h2 style="margin:0;font-size:16px;line-height:20px;font-weight:900;color:#0f172a;">{{ $product->display_name }}</h2>
                    <div style="margin-top:4px;color:#64748b;font-size:12px;">SKU: {{ $product->paloma_sku ?: $product->sku ?: $product->model ?: 'не указан' }}</div>
                    <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
                        <span style="border-radius:999px;padding:4px 8px;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:800;">Score {{ $score }}%</span>
                        <span style="border-radius:999px;padding:4px 8px;background:#f1f5f9;color:#334155;font-size:11px;font-weight:800;">Draft {{ $drafts->count() }}</span>
                        <span style="border-radius:999px;padding:4px 8px;background:#ecfdf5;color:#047857;font-size:11px;font-weight:800;">Approved {{ $approved->count() }}</span>
                        @if($failed->count())
                            <span style="border-radius:999px;padding:4px 8px;background:#fee2e2;color:#dc2626;font-size:11px;font-weight:800;">Failed {{ $failed->count() }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <div style="margin-top:12px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;font-size:12px;color:#475569;">
                <div><strong>Категория:</strong> {{ $product->category?->display_name ?: 'нет' }}</div>
                <div><strong>Бренд:</strong> {{ $product->brand?->display_name ?: 'нет' }}</div>
                <div><strong>Цена:</strong> {{ number_format((float) $product->price, 0, '.', ' ') }} KZT</div>
                <div><strong>Остаток:</strong> {{ (int) $product->quantity }}</div>
                <div><strong>Последнее предложение:</strong> {{ $lastTask?->updated_at?->format('d.m.Y H:i') ?: '-' }}</div>
                <div><strong>Публикация:</strong> {{ $lastPublished?->published_at?->format('d.m.Y H:i') ?: '-' }}</div>
            </div>

            <div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;">
                <a href="{{ ProductResource::getUrl('edit', ['record' => $product]) }}" style="border:0;border-radius:9px;padding:8px 10px;background:#f1f5f9;color:#334155;font-weight:800;font-size:12px;text-decoration:none;">Edit product</a>
                <button type="button" wire:click="createSelectedTasks" style="border:0;border-radius:9px;padding:8px 10px;background:#eff6ff;color:#1d4ed8;font-weight:800;font-size:12px;cursor:pointer;">Generate tasks</button>
                <button type="button" wire:click="generateSelected('all')" style="border:0;border-radius:9px;padding:8px 10px;background:#2563eb;color:#fff;font-weight:800;font-size:12px;cursor:pointer;">Generate draft</button>
                <button type="button" wire:click="approveAllDraftsForSelected" style="border:0;border-radius:9px;padding:8px 10px;background:#dcfce7;color:#15803d;font-weight:800;font-size:12px;cursor:pointer;">Approve</button>
                <button type="button" wire:click="publishApprovedForSelected" style="border:0;border-radius:9px;padding:8px 10px;background:#111827;color:#fff;font-weight:800;font-size:12px;cursor:pointer;">Publish</button>
            </div>
        </div>

        <div style="padding:14px;display:grid;gap:14px;">
            <section>
                <h3 style="margin:0 0 8px;font-size:14px;font-weight:900;color:#0f172a;">Проблемы</h3>
                @php($problems = ContentScore::problems($product))
                @if(empty($problems))
                    <div style="color:#16a34a;font-weight:800;font-size:13px;">Готово</div>
                @else
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        @foreach($problems as $problem)
                            <span style="border-radius:999px;padding:5px 8px;background:#fee2e2;color:#dc2626;font-size:11px;font-weight:800;">{{ $problem }}</span>
                        @endforeach
                    </div>
                @endif
            </section>

            @foreach(['Фото' => ['image'], 'Описание' => ['description'], 'SEO' => ['seo', 'seo_title', 'seo_description'], 'Бренд' => ['brand'], 'Категория' => ['category']] as $section => $types)
                @php($sectionTasks = $tasks->whereIn('task_type', $types))
                <section style="border-top:1px solid #e5e7eb;padding-top:12px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
                        <h3 style="margin:0;font-size:14px;font-weight:900;color:#0f172a;">{{ $section }}</h3>
                        <button type="button" wire:click="generateSelected('{{ $section === 'SEO' ? 'seo' : ($section === 'Фото' ? 'image' : ($section === 'Описание' ? 'description' : ($section === 'Бренд' ? 'brand' : 'category'))) }}')" style="border:0;border-radius:8px;padding:6px 8px;background:#f1f5f9;color:#334155;font-size:11px;font-weight:800;cursor:pointer;">Сгенерировать</button>
                    </div>

                    @forelse($sectionTasks as $task)
                        @php($issues = array_merge(EncodingIssueDetector::findIssues($task->suggested_payload), EncodingIssueDetector::findIssues([$task->suggested_value, $task->reason])))
                        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:10px;background:#fff;margin-bottom:8px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                                <strong style="font-size:12px;color:#0f172a;">{{ $task->task_type }} · {{ $task->status }}</strong>
                                <span style="font-size:11px;color:#64748b;">{{ $task->updated_at?->format('d.m H:i') }} · {{ $task->confidence }}%</span>
                            </div>

                            @if($issues)
                                <div style="margin-top:8px;border-radius:9px;padding:8px;background:#fff7ed;color:#c2410c;font-size:12px;font-weight:800;">Обнаружена проблема кодировки</div>
                            @endif

                            <textarea wire:model.defer="draftEdits.{{ $task->id }}.suggested_value" style="margin-top:8px;width:100%;min-height:82px;border:1px solid #cbd5e1;border-radius:9px;padding:8px;font-size:12px;color:#0f172a;">{{ $task->suggested_value }}</textarea>

                            <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
                                <button type="button" wire:click="saveDraft({{ $task->id }})" style="border:0;border-radius:8px;padding:6px 8px;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:800;cursor:pointer;">Сохранить draft</button>
                                @if($task->status === 'draft')
                                    <button type="button" wire:click="approveTask({{ $task->id }})" style="border:0;border-radius:8px;padding:6px 8px;background:#dcfce7;color:#15803d;font-size:11px;font-weight:800;cursor:pointer;">Одобрить</button>
                                @endif
                                @if($task->status === 'approved')
                                    <button type="button" wire:click="publishTask({{ $task->id }})" style="border:0;border-radius:8px;padding:6px 8px;background:#111827;color:#fff;font-size:11px;font-weight:800;cursor:pointer;">Опубликовать</button>
                                @endif
                                @if(in_array($task->status, ['draft', 'approved', 'failed'], true))
                                    <button type="button" wire:click="rejectTask({{ $task->id }})" style="border:0;border-radius:8px;padding:6px 8px;background:#fee2e2;color:#dc2626;font-size:11px;font-weight:800;cursor:pointer;">Отклонить</button>
                                @endif
                            </div>

                            @if($task->error_message)
                                <div style="margin-top:8px;color:#dc2626;font-size:11px;">{{ $task->error_message }}</div>
                            @endif
                        </div>
                    @empty
                        <div style="color:#64748b;font-size:12px;">Задач пока нет.</div>
                    @endforelse
                </section>
            @endforeach

            <section style="border-top:1px solid #e5e7eb;padding-top:12px;">
                <h3 style="margin:0 0 8px;font-size:14px;font-weight:900;color:#0f172a;">История</h3>
                @forelse($logs as $log)
                    <div style="border:1px solid #e5e7eb;border-radius:10px;padding:8px;background:#fff;margin-bottom:6px;">
                        <div style="font-size:12px;font-weight:800;">{{ $log->created_at?->format('d.m.Y H:i') }} · {{ $log->type }}</div>
                        <div style="font-size:11px;color:#64748b;">Задача #{{ $log->enrichment_task_id ?: 'manual' }}</div>
                    </div>
                @empty
                    <div style="border:1px dashed #cbd5e1;border-radius:10px;padding:12px;background:#f8fafc;color:#64748b;font-size:12px;">Истории изменений пока нет.</div>
                @endforelse
            </section>
        </div>
    @endif
</div>
