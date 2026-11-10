<?php

use App\Livewire\FailingJobsBadge;
use App\Models\BackupJobSetting;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\User;
use Livewire\Livewire;

function badgeResult(BackupServer $server, string $backupId, string $status): void
{
    $result = new BackupResult;
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => 'Job '.$backupId, 'backup-id' => $backupId, 'machine-id' => 'm1'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = $status;
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = now()->utc()->subHour()->format('Y-m-d\TH:i:s\Z');
    $result->BeginTime = now()->utc()->subHour()->format('Y-m-d\TH:i:s\Z');
    $result->Duration = '00:05:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 0;
    $result->BackendStatistics = [];
    $result->save();
}

test('the badge counts failing jobs that are not snoozed', function () {
    $user = User::factory()->create();
    $server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $user->id]);
    badgeResult($server, 'a', 'Error');
    badgeResult($server, 'b', 'Fatal');
    badgeResult($server, 'c', 'Success');
    BackupJobSetting::query()->create(['backup_server_id' => $server->id, 'backup_id' => 'b', 'muted_until' => now()->addDay()]);

    $this->actingAs($user);

    Livewire::test(FailingJobsBadge::class)->assertSee('dcSetFailingJobs?.(1)', false);
});

test('the sidebar has the install item and the badge', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Install app')
        ->assertSee('dcSetFailingJobs?.(0)', false);
});
