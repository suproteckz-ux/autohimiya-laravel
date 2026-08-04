<?php

use App\Http\Controllers\InternalKaspiContentImportController;
use App\Http\Controllers\InternalKaspiContentCandidatesController;
use Illuminate\Support\Facades\Route;

Route::get('/internal/kaspi-content/candidates', InternalKaspiContentCandidatesController::class)
    ->middleware('throttle:kaspi-import');

Route::post('/internal/kaspi-content/import', InternalKaspiContentImportController::class)
    ->middleware('throttle:kaspi-import');
