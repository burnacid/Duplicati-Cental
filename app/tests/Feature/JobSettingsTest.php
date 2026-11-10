<?php

use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\BackupServers\Index as BackupServersIndex;
use App\Models\BackupJobSetting;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\BackupHealthService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function jobResult(BackupServer $server, DateTimeInterface $end, string $status = 'Success', string $backupId = '1'): BackupResult
{
    $result = new BackupResult;
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => 'Documents', 'backup-id' => $backupId, 'machine-id' => 'm1'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = $status;
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = $end->format('Y-m-d\TH:i:s\Z');
    $result->BeginTime = $end->format('Y-m-d\TH:i:s\Z');
    $result->Duration = '00:05:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 0;
    $result->BackendStatistics = ['KnownFileSize' => 1000];
    $result->save();

    return $result;
}

function healthJob(User $user): array
{
    $health = app(BackupHealthService::class);
    $health->flush();

    return $health->jobs($user)->first();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $this->user->id]);
    // Daily backups, the last one 30 hours ago: not overdue with the learned 24 h schedule.
    foreach ([126, 102, 78, 54, 30] as $hoursAgo) {
        jobResult($this->server, now()->utc()->subHours($hoursAgo));
    }
});

test('a learned schedule is used by default', function () {
    $job = healthJob($this->user);

    expect($job['interval_source'])->toBe('learned')
        ->and($job['is_overdue'])->toBeFalse()
        ->and($job['monitored'])->toBeTrue()
        ->and($job['is_muted'])->toBeFalse();
});

test('a manual interval makes the job overdue after one missed run', function () {
    BackupJobSetting::query()->create(['backup_server_id' => $this->server->id, 'backup_id' => '1', 'expected_interval_hours' => 12]);

    $job = healthJob($this->user);

    expect($job['interval_source'])->toBe('manual')
        ->and($job['expected_interval'])->toBe(12 * 3600)
        ->and($job['is_overdue'])->toBeTrue();
});

test('an unmonitored job is never overdue', function () {
    BackupJobSetting::query()->create([
        'backup_server_id' => $this->server->id, 'backup_id' => '1', 'expected_interval_hours' => 1, 'monitored' => false,
    ]);

    expect(healthJob($this->user)['is_overdue'])->toBeFalse();
});

test('a job is muted by its own snooze or by its server snooze', function () {
    $setting = BackupJobSetting::query()->create([
        'backup_server_id' => $this->server->id, 'backup_id' => '1', 'muted_until' => now()->addHour(),
    ]);
    expect(healthJob($this->user)['is_muted'])->toBeTrue();

    $setting->update(['muted_until' => now()->subMinute()]);
    expect(healthJob($this->user)['is_muted'])->toBeFalse();

    $this->server->update(['muted_until' => now()->addDay()]);
    expect(healthJob($this->user)['is_muted'])->toBeTrue();
});

test('no result notification is sent while a server or job is snoozed', function () {
    Http::fake();
    $this->user->notificationChannels()->create([
        'name' => 'Discord', 'type' => 'discord', 'config' => ['webhook_url' => 'https://discord.example/hook'],
        'is_active' => true, 'notify_on' => NotificationChannel::NOTIFY_ALL, 'notify_overdue' => true,
    ]);
    $result = jobResult($this->server, now()->utc(), 'Error');
    $service = app(NotificationService::class);

    $this->server->update(['muted_until' => now()->addHour()]);
    $service->notifyBackupResult($result->fresh());
    Http::assertNothingSent();

    $this->server->update(['muted_until' => null]);
    BackupJobSetting::query()->create(['backup_server_id' => $this->server->id, 'backup_id' => '1', 'muted_until' => now()->addHour()]);
    $service->notifyBackupResult($result->fresh());
    Http::assertNothingSent();

    BackupJobSetting::query()->update(['muted_until' => null]);
    $service->notifyBackupResult($result->fresh());
    Http::assertSentCount(1);
});

test('overdue alerts skip snoozed jobs', function () {
    Http::fake();
    $this->user->notificationChannels()->create([
        'name' => 'Discord', 'type' => 'discord', 'config' => ['webhook_url' => 'https://discord.example/hook'],
        'is_active' => true, 'notify_on' => NotificationChannel::NOTIFY_ALL, 'notify_overdue' => true,
    ]);
    BackupJobSetting::query()->create([
        'backup_server_id' => $this->server->id, 'backup_id' => '1', 'expected_interval_hours' => 12, 'muted_until' => now()->addHour(),
    ]);

    $this->artisan('backups:check-overdue')->assertSuccessful();
    Http::assertNothingSent();

    BackupJobSetting::query()->update(['muted_until' => null]);
    app(BackupHealthService::class)->flush();
    $this->artisan('backups:check-overdue')->assertSuccessful();
    Http::assertSentCount(1);
});

test('the job settings modal saves, snoozes and resumes', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(BackupsIndex::class)
        ->call('openSettings', $this->server->id, '1')
        ->assertSet('settingsBackupName', 'Documents')
        ->set('monitored', false)
        ->set('expectedIntervalHours', '24')
        ->call('saveSettings')
        ->assertHasNoErrors();

    $setting = BackupJobSetting::query()->sole();
    expect($setting->monitored)->toBeFalse()->and($setting->expected_interval_hours)->toBe(24);

    $component->set('snoozeFor', '4h')->call('snoozeJob');
    expect($setting->fresh()->isMuted())->toBeTrue();

    $component->call('resumeJob');
    expect($setting->fresh()->muted_until)->toBeNull();

    $component->call('openSettings', $this->server->id, '1')->set('expectedIntervalHours', '0')->call('saveSettings')
        ->assertHasErrors('expectedIntervalHours');
});

test('servers can be snoozed and resumed from the servers page', function () {
    $this->actingAs($this->user);

    Livewire::test(BackupServersIndex::class)
        ->call('snoozeServer', $this->server->id, '1d')
        ->assertSee('Alerts snoozed');
    expect($this->server->fresh()->isMuted())->toBeTrue();

    Livewire::test(BackupServersIndex::class)->call('resumeServer', $this->server->id);
    expect($this->server->fresh()->isMuted())->toBeFalse();
});
