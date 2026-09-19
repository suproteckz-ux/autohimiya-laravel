<?php

namespace App\Http\Controllers;

use App\Services\Kaspi\KaspiStockFeedGenerator;
use Illuminate\Http\Response;

class KaspiStockFeedController extends Controller
{
    public function __construct(private readonly KaspiStockFeedGenerator $generator) {}

    public function __invoke(): Response
    {
        $xml = $this->generator->generate();

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }
}
