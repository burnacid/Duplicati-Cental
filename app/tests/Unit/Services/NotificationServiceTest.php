<?php

use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['mail.default' => 'array']);
});

function sentMailCount(): int
{
    return app('mailer')->getSymfonyTransport()->messages()->count();
}

function makeChannel(User $user, array $attributes): NotificationChannel
{
    return $user->notificationChannels()->create(array_merge([
        'name' => 'Channel',
        'type' => 'email',
        'config' => ['to' => 'ops@example.com'],
        'is_active' => true,
        'notify_on' => NotificationChannel::NOTIFY_WARNING,
        'notify_overdue' => true,
    ], $attributes));
}

function makeResult(User $user, string $status): BackupResult
{
    $server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $user->id]);

    $result = new BackupResult;
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => 'Documents', 'backup-id' => '1', 'machine-id' => 'm1'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = $status;
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = '2026-04-04T10:05:00Z';
    $result->BeginTime = '2026-04-04T10:00:00Z';
    $result->Duration = '00:05:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = $status === 'Error' ? 1 : 0;
    $result->BackendStatistics = [];
    $result->save();

    return $result->load('backupServer.user');
}

test('it sends to active email, discord, and webhook channels', function () {
    $user = User::factory()->create();

    makeChannel($user, ['name' => 'Ops Email', 'type' => 'email', 'config' => ['to' => 'ops@example.com']]);
    makeChannel($user, ['name' => 'Discord', 'type' => 'discord', 'config' => ['webhook_url' => 'https://discord.example/webhook']]);
    makeChannel($user, ['name' => 'Webhook', 'type' => 'webhook', 'config' => [
        'url' => 'https://api.example/notify',
        'method' => 'POST',
        'headers' => ['X-Test' => '1'],
    ]]);
    makeChannel($user, ['name' => 'Disabled', 'type' => 'discord', 'is_active' => false, 'config' => ['webhook_url' => 'https://discord.example/disabled']]);

    Http::fake();

    app(NotificationService::class)->send($user, 'Backup failed');

    expect(sentMailCount())->toBe(1);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://discord.example/webhook'
        && $request['content'] === 'Backup failed');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example/notify'
        && $request['message'] === 'Backup failed'
        && $request->hasHeader('X-Test', '1'));
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://discord.example/disabled');
});

test('it does nothing when there are no active channels', function () {
    $user = User::factory()->create();
    makeChannel($user, ['is_active' => false]);

    Http::fake();

    app(NotificationService::class)->send($user, 'Backup failed');

    expect(sentMailCount())->toBe(0);
    Http::assertNothingSent();
});

test('backup result notifications respect the channel level', function () {
    $user = User::factory()->create();
    makeChannel($user, ['name' => 'All', 'type' => 'discord', 'notify_on' => 'all', 'config' => ['webhook_url' => 'https://discord.example/all']]);
    makeChannel($user, ['name' => 'Warn', 'type' => 'discord', 'notify_on' => 'warning', 'config' => ['webhook_url' => 'https://discord.example/warn']]);
    makeChannel($user, ['name' => 'Err', 'type' => 'discord', 'notify_on' => 'error', 'config' => ['webhook_url' => 'https://discord.example/err']]);

    Http::fake();
    app(NotificationService::class)->notifyBackupResult(makeResult($user, 'Warning'));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://discord.example/all');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://discord.example/warn');
    Http::assertNotSent(fn (Request $r) => $r->url() === 'https://discord.example/err');
});

test('success results only go to channels that want everything', function () {
    $user = User::factory()->create();
    makeChannel($user, ['name' => 'Warn', 'type' => 'discord', 'notify_on' => 'warning', 'config' => ['webhook_url' => 'https://discord.example/warn']]);

    Http::fake();
    app(NotificationService::class)->notifyBackupResult(makeResult($user, 'Success'));

    Http::assertNothingSent();
});

test('a failing channel does not block the others', function () {
    $user = User::factory()->create();
    makeChannel($user, ['name' => 'A', 'type' => 'discord', 'config' => ['webhook_url' => 'https://discord.example/broken']]);
    makeChannel($user, ['name' => 'B', 'type' => 'discord', 'config' => ['webhook_url' => 'https://discord.example/ok']]);

    Http::fake([
        'discord.example/broken' => Http::response('nope', 500),
        '*' => Http::response('ok'),
    ]);

    app(NotificationService::class)->send($user, 'Backup failed');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://discord.example/ok');
});

test('webhooks only allow known HTTP methods', function () {
    $user = User::factory()->create();
    $channel = makeChannel($user, ['type' => 'webhook', 'config' => ['url' => 'https://api.example/x', 'method' => 'withOptions']]);

    Http::fake();

    expect(fn () => app(NotificationService::class)->sendTest($channel))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('channel config is encrypted at rest', function () {
    $user = User::factory()->create();
    makeChannel($user, ['config' => ['to' => 'ops@example.com', 'password' => 'hunter2']]);

    $raw = DB::table('notification_channels')->value('config');

    expect($raw)->not->toContain('hunter2')
        ->and(NotificationChannel::first()->config['password'])->toBe('hunter2');
});
