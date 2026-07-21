@php
    $kaspiImages = (array) data_get($task?->raw_payload, 'cleaned.images', $task?->parsed_images ?: []);
    $kaspiDescription = data_get($task?->raw_payload, 'cleaned.description', $task?->parsed_description);
    $kaspiAttributes = (array) data_get($task?->raw_payload, 'cleaned.attributes', $task?->parsed_attributes ?: []);
    $debug = (array) data_get($task?->raw_payload, 'debug', []);
    $diagnostics = (array) data_get($task?->raw_payload, 'diagnostics', []);
    $siteImagesCount = $product->images_count ?? $product->images()->count();
    $siteAttributesCount = $product->attributes_count ?? $product->attributes()->count();
    $applyPhoto = ! \App\Support\ContentScore::hasPhoto($product) && $kaspiImages !== [];
    $applyDescription = blank($product->description) && filled($kaspiDescription);
    $applyAttributes = $kaspiAttributes !== [];
@endphp

<div class="space-y-5 text-sm">
    @if(! $task)
        <div class="rounded-lg bg-gray-50 p-4 text-gray-600 dark:bg-gray-900 dark:text-gray-300">
            Draft не найден. Сначала создайте задачу для товара с Kaspi-кнопкой.
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                <div class="mb-3 text-base font-semibold">Сайт</div>
                <div class="mb-4 grid gap-3 sm:grid-cols-[96px_1fr]">
                    <div class="flex h-24 items-center justify-center rounded-lg bg-gray-50 p-2 dark:bg-gray-900">
                        @if($product->primaryImage?->storefrontCardImagePath())
                            <img src="{{ asset('storage/'.$product->primaryImage->storefrontCardImagePath()) }}" alt="" class="max-h-20 w-full object-contain">
                        @else
                            <span class="text-xs text-gray-500">Нет фото</span>
                        @endif
                    </div>
                    <dl class="space-y-1">
                        <div><dt class="text-xs text-gray-500">Название</dt><dd>{{ $product->display_name }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Бренд</dt><dd>{{ $product->brand?->name ?: 'Нет' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Категория</dt><dd>{{ $product->category?->name ?: 'Нет' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">На сайте фото</dt><dd>{{ $siteImagesCount }}</dd></div>
                    </dl>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                    <div class="mb-1 text-xs font-semibold text-gray-500">Описание</div>
                    <div class="text-gray-700 dark:text-gray-300">
                        {{ filled($product->description) ? \Illuminate\Support\Str::limit(strip_tags($product->description), 600) : 'Нет описания' }}
                    </div>
                </div>
                <div class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                    <div class="mb-1 text-xs font-semibold text-gray-500">Характеристики</div>
                    <div>{{ $siteAttributesCount ?: 'Нет характеристик' }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                <div class="mb-3 text-base font-semibold">Kaspi</div>
                <div class="mb-4 grid gap-3 sm:grid-cols-[96px_1fr]">
                    <div class="flex h-24 items-center justify-center rounded-lg bg-gray-50 p-2 dark:bg-gray-900">
                        @if(($kaspiImages[0] ?? null))
                            <img src="{{ $kaspiImages[0] }}" alt="" class="max-h-20 w-full object-contain">
                        @else
                            <span class="text-xs text-gray-500">Нет фото</span>
                        @endif
                    </div>
                    <dl class="space-y-1">
                        <div><dt class="text-xs text-gray-500">Status</dt><dd>{{ $task->status }}</dd></div>
                        <div><dt class="text-xs text-gray-500">URL</dt><dd class="break-all">{{ $task->kaspi_product_url ?: 'Нужно указать вручную' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Название</dt><dd>{{ data_get($task->parsed_title, 'value') ?: 'Нет' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Kaspi фото</dt><dd>{{ count($kaspiImages) }}</dd></div>
                    </dl>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                    <div class="mb-1 text-xs font-semibold text-gray-500">Очищенное описание</div>
                    <div class="prose prose-sm max-w-none text-gray-700 dark:prose-invert dark:text-gray-300">
                        @if(filled($kaspiDescription))
                            {!! $kaspiDescription !!}
                        @else
                            Описание пока не найдено
                        @endif
                    </div>
                </div>
                <div class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                    <div class="mb-1 text-xs font-semibold text-gray-500">Очищенные характеристики</div>
                    <div>{{ count($kaspiAttributes) ?: 'Пока не найдены' }}</div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <div class="mb-3 text-sm font-semibold">Что будет применено при Publish</div>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="flex items-center gap-2">
                    <input type="checkbox" @checked($applyPhoto) disabled>
                    <span>применить фото ({{ count($kaspiImages) }})</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" @checked($applyDescription) disabled>
                    <span>применить описание</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" @checked($applyAttributes) disabled>
                    <span>применить характеристики ({{ count($kaspiAttributes) }})</span>
                </label>
            </div>
        </div>

        @if($kaspiImages !== [])
            <div>
                <div class="mb-2 text-sm font-semibold">Все найденные фото Kaspi</div>
                <div class="grid gap-2 md:grid-cols-6">
                    @foreach($kaspiImages as $image)
                        <div class="rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-800 dark:bg-gray-950">
                            <img src="{{ $image }}" alt="" class="h-20 w-full object-contain">
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <div class="mb-2 text-sm font-semibold">Характеристики Kaspi</div>
            @if($kaspiAttributes === [])
                <div class="text-gray-600 dark:text-gray-300">
                    Чистые характеристики пока не найдены.
                </div>
            @else
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($kaspiAttributes as $attribute)
                            <tr>
                                <th class="w-1/3 py-2 pr-3 font-medium">{{ $attribute['name'] ?? '' }}</th>
                                <td class="py-2">{{ $attribute['value'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <details class="rounded-xl border border-gray-200 p-4 text-xs text-gray-600 dark:border-gray-800 dark:text-gray-300">
            <summary class="cursor-pointer font-semibold">Debug / parser diagnostics</summary>
            <div class="mt-3 grid gap-2 md:grid-cols-3">
                @forelse($diagnostics as $label => $value)
                    <div>{{ $label }}: {{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</div>
                @empty
                    <div>Diagnostics are not available yet.</div>
                @endforelse
            </div>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <div class="mb-1 font-semibold">Raw description</div>
                    <pre class="max-h-52 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-3 dark:bg-gray-900">{{ data_get($debug, 'raw_description') ?: 'empty' }}</pre>
                </div>
                <div>
                    <div class="mb-1 font-semibold">Cleaned description</div>
                    <pre class="max-h-52 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-3 dark:bg-gray-900">{{ data_get($debug, 'cleaned_description') ?: 'empty' }}</pre>
                </div>
                <div>
                    <div class="mb-1 font-semibold">Excluded description lines</div>
                    <pre class="max-h-52 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-3 dark:bg-gray-900">{{ json_encode(data_get($debug, 'excluded_description_lines', []), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                </div>
                <div>
                    <div class="mb-1 font-semibold">Excluded attributes</div>
                    <pre class="max-h-52 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-3 dark:bg-gray-900">{{ json_encode(data_get($debug, 'excluded_attributes', []), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                </div>
                <div>
                    <div class="mb-1 font-semibold">Rejected images</div>
                    <pre class="max-h-52 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-3 dark:bg-gray-900">{{ json_encode(data_get($debug, 'rejected_images', []), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>

            @if(filled($task->error))
                <div class="mt-3 text-danger-600">{{ $task->error }}</div>
            @endif
        </details>
    @endif
</div>
