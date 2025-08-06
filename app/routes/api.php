<?php

use App\Http\Controllers\Api\BackupController;
use Illuminate\Support\Facades\Route;

Route::post('/backup-servers/{serverId}/{apiKey}/backup-result', [BackupController::class, 'storeBackupResult']);
