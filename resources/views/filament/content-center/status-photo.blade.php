@include('filament.content-center.status', ['ok' => \App\Support\ContentScore::hasPhoto($record), 'missing' => 'Нет фото'])
