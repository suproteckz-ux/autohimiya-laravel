<?php

namespace App\Http\Controllers;

use App\Services\Kaspi\KaspiInternalApiAuthenticator;
use App\Services\Kaspi\KaspiProductionCandidateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalKaspiContentCandidatesController extends Controller
{
    public function __invoke(Request $request, KaspiInternalApiAuthenticator $authenticator, KaspiProductionCandidateService $service): JsonResponse
    {
        if (! $authenticator->httpsAllowed($request)) {
            return response()->json(['ok' => false, 'error' => 'https_required'], 403);
        }

        if (! $authenticator->authorized($request)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        return response()->json($service->list([
            'sku' => $request->query('sku', []),
            'limit' => $request->query('limit'),
            'cursor' => $request->query('cursor'),
            'page' => $request->query('page'),
            'missing' => $request->query('missing', 'content'),
            'include_protected' => $request->query('include_protected', false),
            'debug' => $request->query('debug', false),
        ]));
    }
}
