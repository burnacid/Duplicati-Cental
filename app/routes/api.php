<?php

use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\RemoteControlCertificateController;
use App\Http\Controllers\Api\RemoteControlRegistrationController;
use Illuminate\Support\Facades\Route;

Route::post('/backup-servers/{serverId}/{apiKey}/backup-result', [BackupController::class, 'storeBackupResult']);

Route::middleware('remote-control')->group(function () {
    Route::post('/remote-control/register', [RemoteControlRegistrationController::class, 'register'])
        ->name('api.remote-control.register');
    Route::post('/remote-control/register/{registrationToken}/status', [RemoteControlRegistrationController::class, 'status'])
        ->name('api.remote-control.status');

    Route::get('/remote-control/certificates', [RemoteControlCertificateController::class, 'index'])
        ->name('api.remote-control.certificates');
});
