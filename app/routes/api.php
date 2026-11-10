<?php

use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\RemoteControlCertificateController;
use App\Http\Controllers\Api\RemoteControlRegistrationController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\ReadApiController;
use Illuminate\Support\Facades\Route;

Route::post('/backup-servers/{serverId}/{apiKey}/backup-result', [BackupController::class, 'storeBackupResult']);

// Read-only API and Prometheus metrics, with personal access tokens (Settings → API tokens).
Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'abilities:read', 'throttle:api'])->group(function () {
    Route::get('/summary', [ReadApiController::class, 'summary'])->name('summary');
    Route::get('/servers', [ReadApiController::class, 'servers'])->name('servers');
    Route::get('/jobs', [ReadApiController::class, 'jobs'])->name('jobs');
    Route::get('/results', [ReadApiController::class, 'results'])->name('results');
    Route::get('/results/{id}', [ReadApiController::class, 'result'])->whereNumber('id')->name('result');
    Route::get('/metrics', MetricsController::class)->name('metrics');
});

Route::middleware('remote-control')->group(function () {
    Route::post('/remote-control/register', [RemoteControlRegistrationController::class, 'register'])
        ->name('api.remote-control.register');
    Route::post('/remote-control/register/{registrationToken}/status', [RemoteControlRegistrationController::class, 'status'])
        ->name('api.remote-control.status');

    Route::get('/remote-control/certificates', [RemoteControlCertificateController::class, 'index'])
        ->name('api.remote-control.certificates');
});
