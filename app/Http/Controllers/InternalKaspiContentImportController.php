<?php

namespace App\Http\Controllers;

use App\Services\Kaspi\KaspiProductionImportService;
use App\Services\Kaspi\KaspiInternalApiAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InternalKaspiContentImportController extends Controller
{
    public function __invoke(Request $request, KaspiProductionImportService $service, KaspiInternalApiAuthenticator $authenticator): JsonResponse
    {
        if (! $authenticator->httpsAllowed($request)) {
            return response()->json(['ok' => false, 'error' => 'https_required'], 403);
        }

        if (! $authenticator->authorized($request)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        try {
            $result = $service->import($request->json()->all(), strlen($request->getContent()));
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'fields' => $exception->errors(),
            ], 422);
        }

        return response()->json($result['body'], $result['http_status']);
    }
}
