<?php

use App\Livewire\NotificationChannels\Index;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function channelFor(User $user, string $type, array $config): NotificationChannel
{
    return $user->notificationChannels()->create([
        'name' => ucfirst($type), 'type' => $type, 'config' => $config,
        'is_active' => true, 'notify_on' => NotificationChannel::NOTIFY_ALL, 'notify_overdue' => true,
    ]);
}

function failedResultFor(User $user): BackupResult
{
    $server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $user->id]);
    $result = new BackupResult;
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => 'Documents', 'backup-id' => '1', 'machine-id' => 'm1'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = 'Error';
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = '2026-04-04T10:05:00Z';
    $result->BeginTime = '2026-04-04T10:00:00Z';
    $result->Duration = '00:05:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 1;
    $result->BackendStatistics = [];
    $result->save();

    return $result->load('backupServer.user');
}

test('each new channel type sends the expected request', function () {
    Http::fake();
    $user = User::factory()->create();
    channelFor($user, 'slack', ['webhook_url' => 'https://hooks.slack.test/abc']);
    channelFor($user, 'teams', ['webhook_url' => 'https://teams.test/workflow']);
    channelFor($user, 'telegram', ['bot_token' => '123:abc', 'chat_id' => '-100200']);
    channelFor($user, 'ntfy', ['server_url' => 'https://ntfy.test/', 'topic' => 'backups', 'token' => 'tk_secret']);
    channelFor($user, 'pushover', ['app_token' => 'apptoken', 'user_key' => 'userkey']);

    app(NotificationService::class)->notifyBackupResult(failedResultFor($user));

    Http::assertSentCount(5);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://hooks.slack.test/abc' && str_contains($r['text'], '*[Error] NAS - Documents*'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://teams.test/workflow'
        && $r['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
        && $r['attachments'][0]['content']['body'][0]['text'] === '[Error] NAS - Documents'
        && $r['attachments'][0]['content']['actions'][0]['type'] === 'Action.OpenUrl');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.telegram.org/bot123:abc/sendMessage' && $r['chat_id'] === '-100200');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://ntfy.test'
        && $r['topic'] === 'backups' && $r['priority'] === 4
        && $r->hasHeader('Authorization', 'Bearer tk_secret'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.pushover.net/1/messages.json'
        && $r['token'] === 'apptoken' && $r['user'] === 'userkey' && (int) $r['priority'] === 1);
});

test('a new channel type can be created and keeps its secret when edited', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Phone')
        ->set('type', 'telegram')
        ->set('config.bot_token', '123:abc')
        ->set('config.chat_id', '42')
        ->set('digest', 'weekly')
        ->call('save')
        ->assertHasNoErrors();

    $channel = NotificationChannel::query()->sole();
    expect($channel->config)->toBe(['bot_token' => '123:abc', 'chat_id' => '42'])
        ->and($channel->digest)->toBe('weekly');

    Livewire::test(Index::class)
        ->call('edit', $channel->id)
        ->assertSet('config.bot_token', '')
        ->set('config.chat_id', '43')
        ->call('save')
        ->assertHasNoErrors();

    expect($channel->fresh()->config)->toBe(['bot_token' => '123:abc', 'chat_id' => '43']);
});

test('new channel types are validated', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Bad')
        ->set('type', 'ntfy')
        ->set('config.topic', 'has spaces')
        ->call('save')
        ->assertHasErrors('config.topic');

    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Bad')
        ->set('type', 'pushover')
        ->call('save')
        ->assertHasErrors(['config.app_token', 'config.user_key']);
});
