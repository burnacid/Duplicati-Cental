<?php

use App\Jobs\PingHeartbeat;
use App\Jobs\SendBackupResultNotification;
use App\Livewire\Backups\Index as BackupsIndex;
use App\Models\BackupJobSetting;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\BackupAlertRuleService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** A backup run $daysAgo days ago, lasting $minutes, with the given counters. */
function alertRun(BackupServer $server, int $daysAgo, array $data = []): BackupResult
{
    $end = now()->subDays($daysAgo);
    $result = new BackupResult;
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => 'Docs', 'backup-id' => 'DB-1', 'machine-id' => 'm'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = $data['status'] ?? 'Success';
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = $end;
    $result->BeginTime = $end->copy()->subMinutes($data['minutes'] ?? 10);
    $result->Duration = '00:10:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 0;
    $result->SizeOfExaminedFiles = $data['size'] ?? 1_000_000;
    $result->AddedFiles = $data['added'] ?? 1;
    $result->ModifiedFiles = $data['modified'] ?? 1;
    $result->DeletedFiles = 0;
    $result->BackendStatistics = ['TotalQuotaSpace' => 1000, 'FreeQuotaSpace' => $data['free'] ?? 500];
    $result->save();

    return $result;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $this->user->id]);
    $this->rules = app(BackupAlertRuleService::class);
    for ($day = 10; $day >= 2; $day--) {
        alertRun($this->server, $day);
    }
});

function alertSetting(BackupServer $server, array $values): BackupJobSetting
{
    return $server->jobSettings()->updateOrCreate(['backup_id' => 'DB-1'], $values);
}

test('without rules nothing fires', function () {
    expect($this->rules->check(alertRun($this->server, 1, ['size' => 9_000_000, 'minutes' => 120])))->toBe([]);
});

test('a large size change fires, a small one does not', function () {
    alertSetting($this->server, ['alert_size_change_percent' => 50]);

    expect($this->rules->check(alertRun($this->server, 1, ['size' => 1_200_000])))->toBe([]);

    $alerts = $this->rules->check(alertRun($this->server, 0, ['size' => 3_000_000]));
    expect($alerts)->toHaveCount(1)->and($alerts[0])->toContain('+150%');
});

test('a run much longer than usual fires', function () {
    alertSetting($this->server, ['alert_duration_factor' => 3]);

    expect($this->rules->check(alertRun($this->server, 1, ['minutes' => 25])))->toBe([]);

    $alerts = $this->rules->check(alertRun($this->server, 0, ['minutes' => 45]));
    expect($alerts)->toHaveCount(1)->and($alerts[0])->toContain('4.5×');
});

test('no changes for N runs fires once per streak', function () {
    alertSetting($this->server, ['alert_unchanged_runs' => 2]);

    expect($this->rules->check(alertRun($this->server, 1, ['added' => 0, 'modified' => 0])))->toBe([]);
    expect($this->rules->check(alertRun($this->server, 0, ['added' => 0, 'modified' => 0])))->toHaveCount(1);
    // The streak continues: not reported again.
    $third = alertRun($this->server, 0, ['added' => 0, 'modified' => 0]);
    $third->EndTime = now()->addMinute();
    $third->save();
    expect($this->rules->check($third))->toBe([]);
});

test('low free space fires when it crosses the limit', function () {
    alertSetting($this->server, ['alert_quota_free_percent' => 10]);

    $alerts = $this->rules->check(alertRun($this->server, 1, ['free' => 80]));
    expect($alerts)->toHaveCount(1)->and($alerts[0])->toContain('8.0%');

    // Still low on the next run: already reported.
    expect($this->rules->check(alertRun($this->server, 0, ['free' => 70])))->toBe([]);
});

test('failed runs never trigger rules', function () {
    alertSetting($this->server, ['alert_size_change_percent' => 10]);

    expect($this->rules->check(alertRun($this->server, 1, ['size' => 9_000_000, 'status' => 'Error'])))->toBe([]);
});

test('alerts go to channels that receive warnings, and not while snoozed', function () {
    Http::fake();
    alertSetting($this->server, ['alert_size_change_percent' => 50]);
    NotificationChannel::query()->create(['user_id' => $this->user->id, 'name' => 'Hook', 'type' => 'webhook',
        'config' => ['url' => 'https://hooks.example/warn', 'method' => 'POST', 'headers' => []], 'notify_on' => 'warning']);
    NotificationChannel::query()->create(['user_id' => $this->user->id, 'name' => 'Errors', 'type' => 'webhook',
        'config' => ['url' => 'https://hooks.example/error', 'method' => 'POST', 'headers' => []], 'notify_on' => 'error']);

    (new SendBackupResultNotification(alertRun($this->server, 1, ['size' => 5_000_000])->id))->handle(app(NotificationService::class), $this->rules);

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example/warn' && $request['event'] === 'backup_alert');
    Http::assertNotSent(fn ($request) => $request->url() === 'https://hooks.example/error');

    Http::fake();
    alertSetting($this->server, ['muted_until' => now()->addDay()]);
    (new SendBackupResultNotification(alertRun($this->server, 0, ['size' => 20_000_000])->id))->handle(app(NotificationService::class), $this->rules);
    Http::assertNothingSent();
});

test('a successful report pings the heartbeat URL, a failed one does not', function () {
    Http::fake();
    alertSetting($this->server, ['heartbeat_url' => 'https://kuma.example/api/push/abc?status=up']);

    $ok = alertRun($this->server, 1);
    $failed = alertRun($this->server, 0, ['status' => 'Error']);
    (new PingHeartbeat($ok->id))->handle();
    (new PingHeartbeat($failed->id))->handle();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://kuma.example/api/push/abc?status=up' && $request->method() === 'GET');

    // Stored encrypted.
    expect(DB::table('backup_job_settings')->value('heartbeat_url'))->not->toContain('kuma.example');
});

test('the report API queues a heartbeat only for jobs with a URL', function () {
    Queue::fake();
    $payload = fn (string $status) => [
        'Data' => ['MainOperation' => 'Backup', 'ParsedResult' => $status, 'EndTime' => now()->toIso8601String(), 'BeginTime' => now()->subMinutes(3)->toIso8601String()],
        'Extra' => ['machine-id' => 'm', 'machine-name' => 'M', 'backup-name' => 'Docs', 'backup-id' => 'DB-1'],
    ];
    $url = "/api/backup-servers/{$this->server->id}/{$this->server->api_key}/backup-result";

    $this->postJson($url, $payload('Success'))->assertCreated();
    Queue::assertNotPushed(PingHeartbeat::class);

    alertSetting($this->server, ['heartbeat_url' => 'https://hc.example/ping/1']);
    $this->postJson($url, $payload('Success'))->assertCreated();
    $this->postJson($url, $payload('Error'))->assertCreated();
    Queue::assertPushed(PingHeartbeat::class, 1);
});

test('alert rules, heartbeat and status page are saved from job settings', function () {
    $this->actingAs($this->user);

    Livewire::test(BackupsIndex::class)
        ->call('openSettings', $this->server->id, 'DB-1')
        ->set('alertSizeChangePercent', '40')
        ->set('alertDurationFactor', '2.5')
        ->set('alertUnchangedRuns', '')
        ->set('alertQuotaFreePercent', '15')
        ->set('heartbeatUrl', 'https://hc.example/ping/xyz')
        ->set('onStatusPage', true)
        ->set('statusPageName', 'Website backup')
        ->call('saveSettings')
        ->assertHasNoErrors();

    $setting = $this->server->jobSettings()->where('backup_id', 'DB-1')->first();
    expect($setting->alert_size_change_percent)->toBe(40)
        ->and($setting->alert_duration_factor)->toBe(2.5)
        ->and($setting->alert_unchanged_runs)->toBeNull()
        ->and($setting->alert_quota_free_percent)->toBe(15)
        ->and($setting->heartbeat_url)->toBe('https://hc.example/ping/xyz')
        ->and($setting->on_status_page)->toBeTrue()
        ->and($setting->status_page_name)->toBe('Website backup');

    Livewire::test(BackupsIndex::class)
        ->call('openSettings', $this->server->id, 'DB-1')
        ->assertSet('heartbeatUrl', 'https://hc.example/ping/xyz')
        ->set('heartbeatUrl', 'ftp://nope')
        ->set('alertQuotaFreePercent', '150')
        ->call('saveSettings')
        ->assertHasErrors(['heartbeatUrl', 'alertQuotaFreePercent']);
});
