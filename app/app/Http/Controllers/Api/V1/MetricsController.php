<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MetricsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Prometheus scrape endpoint. Use an API token as bearer token:
 *
 *   - job_name: duplicati-central
 *     metrics_path: /api/v1/metrics
 *     authorization: { credentials: "<token>" }
 *     static_configs: [{ targets: ["central.example.com"] }]
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request, MetricsService $metrics): Response
    {
        return response($metrics->render($request->user()), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
