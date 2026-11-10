<?php

use App\Models\BackupJobSetting;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\DigestService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function digestResult(BackupServer $server, string $backupId, string $status, int $hoursAgo): void
{
    $result = new BackupResult();
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => 'Job ' . $backupId, 'backup-id' => $backupId, 'machine-id' => 'm1'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = $status;
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    $result->BeginTime = now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    $result->Duration = '00:05:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 0;
    $result->BackendStatistics = ['KnownFileSize' => 1024 * 1024];
    $result->save();
}

function digestChannel(User $user, string $digest, string $url): NotificationChannel
{
    return $user->notificationChannels()->create([
        'name' => $digest, 'type' => 'discord', 'config' => ['webhook_url' => $url],
        'is_active' => true, 'notify_on' => NotificationChannel::NOTIFY_ERROR, 'notify_overdue' => false, 'digest' => $digest,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $this->user->id]);
    digestResult($this->server, 'a', 'Success', 2);
    digestResult($this->server, 'b', 'Error', 3);
    digestResult($this->server, 'c', 'Warning', 4);
    digestResult($this->server, 'a', 'Success', 30); // outside the daily window
});

test('the digest counts runs and lists jobs that need attention', function () {
    BackupJobSetting::query()->create(['backup_server_id' => $this->server->id, 'backup_id' => 'c', 'muted_until' => now()->addDay()]);

    $digest = app(DigestService::class)->build($this->user, 'daily');

    expect($digest['subject'])->toBe('[Summary] Daily backup summary: 1 need attention')
        ->and($digest['message'])->toContain('Backups in the last 24 hours: 3 runs, 1 successful, 1 with warnings, 1 failed.')
        ->and($digest['message'])->toContain('- NAS / Job b: Error')
        ->and($digest['message'])->toContain("Snoozed (1):\n- NAS / Job c")
        ->and($digest['message'])->toContain('3 backup jobs, 3 MB stored')
        ->and($digest['context']['runs'])->toBe(3);

    expect(app(DigestService::class)->build($this->user, 'weekly')['message'])->toContain('4 runs');
});

test('the digest is null for users without jobs', function () {
    expect(app(DigestService::class)->build(User::factory()->create(), 'daily'))->toBeNull();
});

test('the command sends only to channels with that digest frequency', function () {
    Http::fake();
    digestChannel($this->user, 'daily', 'https://discord.test/daily');
    digestChannel($this->user, 'weekly', 'https://discord.test/weekly');
    digestChannel($this->user, 'off', 'https://discord.test/off');

    $this->artisan('backups:send-digest daily')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://discord.test/daily' && str_contains($r['content'], 'Daily backup summary'));

    $this->artisan('backups:send-digest monthly')->assertFailed();
});
