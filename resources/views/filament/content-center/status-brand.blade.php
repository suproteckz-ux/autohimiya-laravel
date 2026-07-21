@include('filament.content-center.status', ['ok' => \App\Support\ContentScore::hasBrand($record), 'missing' => 'Нет бренда'])
