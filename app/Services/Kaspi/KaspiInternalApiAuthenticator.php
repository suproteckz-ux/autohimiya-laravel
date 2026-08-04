<?php

namespace App\Services\Kaspi;

use Illuminate\Http\Request;

class KaspiInternalApiAuthenticator
{
    public function authorized(Request $request): bool
    {
        $expected = (string) config('services.kaspi.production_import_token', '');
        $provided = (string) $request->bearerToken();

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }

    public function httpsAllowed(Request $request): bool
    {
        return ! app()->isProduction()
            || $request->isSecure()
            || $request->header('X-Forwarded-Proto') === 'https';
    }
}
