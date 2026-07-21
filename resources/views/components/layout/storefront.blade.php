@props([
    'title' => 'Автохимия.kz',
    'description' => 'Автохимия и автокосметика в Алматы.',
    'canonical' => url()->current(),
    'noindex' => false,
])

@include('layout.storefront', [
    'title' => $title,
    'description' => $description,
    'canonical' => $canonical,
    'noindex' => $noindex,
    'slot' => $slot,
])
