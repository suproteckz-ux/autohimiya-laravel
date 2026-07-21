<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Приоритет наполнения
        </x-slot>

        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
            @foreach($items as $item)
                <a href="{{ $item['url'] }}" style="display:block;border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fff;text-decoration:none;">
                    <div style="font-size:13px;color:#6b7280;">{{ $item['label'] }}</div>
                    <div style="margin-top:6px;font-size:28px;font-weight:800;color:#111827;">{{ $item['count'] }}</div>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
