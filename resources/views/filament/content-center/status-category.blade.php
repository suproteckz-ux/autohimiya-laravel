@include('filament.content-center.status', ['ok' => \App\Support\ContentScore::hasCategory($record), 'missing' => 'Нет категории'])
