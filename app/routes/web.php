<?php

use App\Http\Controllers\RemoteControlClaimController;
use App\Livewire\BackupDetail;
use App\Livewire\BackupResults;
use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\BackupServers\Create as BackupServersCreate;
use App\Livewire\BackupServers\Edit as BackupServersEdit;
use App\Livewire\BackupServers\Index as BackupServersIndex;
use App\Livewire\NotificationChannels\Index as NotificationChannelsIndex;
use App\Livewire\RemoteControl\Index as RemoteControlIndex;
use App\Livewire\RemoteControl\Show as RemoteControlShow;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

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
    Route::get('/backup-results', BackupResults::class)->name('backup-results');
    Route::get('/backup-detail/{id}', BackupDetail::class)->name('backup-detail');
    Route::get('/backups', BackupsIndex::class)->name('backups');
    Route::middleware('remote-control')->group(function () {
        Route::get('/remote-control', RemoteControlIndex::class)->name('remote-control.index');
        Route::get('/remote-control/claim/{claimToken}', [RemoteControlClaimController::class, 'show'])->name('remote-control.claim.show');
        Route::post('/remote-control/claim/{claimToken}', [RemoteControlClaimController::class, 'store'])->name('remote-control.claim.store');
        Route::get('/remote-control/{id}', RemoteControlShow::class)->name('remote-control.show');
    });
    Route::get('/notification-channels', NotificationChannelsIndex::class)->name('notification-channels.index');
});

require __DIR__.'/auth.php';
