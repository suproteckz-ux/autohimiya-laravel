@include('filament.content-center.status', ['ok' => \App\Support\ContentScore::hasSeo($record), 'missing' => 'Нет SEO'])
