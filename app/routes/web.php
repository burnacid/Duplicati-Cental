<?php

use App\Http\Controllers\Api\BackupController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

use App\Livewire\BackupServers\Index as BackupServersIndex;
use App\Livewire\BackupServers\Create as BackupServersCreate;
use App\Livewire\BackupServers\Edit as BackupServersEdit;



Route::middleware(['auth'])->group(function () {
    Route::get('/', function () {
        return view('dashboard');
    })->name('home');
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');

    Route::get('/backup-servers', BackupServersIndex::class)->name('backup-servers.index');
    Route::get('/backup-servers/create', BackupServersCreate::class)->name('backup-servers.create');
    Route::get('/backup-servers/{backupServer}/edit', BackupServersEdit::class)->name('backup-servers.edit');
});

require __DIR__.'/auth.php';


Route::apiResource('api/backup-result', BackupController::class);

//Route::middleware(['api', 'auth:sanctum'])->group(function () {
//
//});
