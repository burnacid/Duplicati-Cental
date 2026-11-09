<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RemoteControlCertificateService;
use Illuminate\Http\JsonResponse;

class RemoteControlCertificateController extends Controller
{
    public function index(RemoteControlCertificateService $certificateService): JsonResponse
    {
        return response()->json($certificateService->listCertificates());
    }
}

