@include('filament.content-center.status', ['ok' => \App\Support\ContentScore::hasDescription($record), 'missing' => 'Нет описания'])
