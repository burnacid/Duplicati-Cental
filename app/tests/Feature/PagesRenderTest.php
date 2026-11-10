<?php

use App\Livewire\NotificationChannels\Index as NotificationChannelsIndex;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $this->user->id]);
});

test('detail page renders structured log lines and backend statistics', function () {
    $result = new BackupResult;
    $result->backup_server_id = $this->server->id;
    $result->extra = ['backup-name' => 'Docs', 'backup-id' => '1', 'machine-id' => 'm', 'machine-name' => 'Host'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = 'Error';
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = now();
    $result->BeginTime = now()->subMinutes(5);
    $result->Duration = '1.00:05:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 1;
    $result->BackendStatistics = ['KnownFileSize' => 2048, 'KnownFileCount' => 7, 'Nested' => ['a' => 1]];
    $result->log_lines = [['Timestamp' => '2026-01-01T00:00:00', 'Message' => 'Disk full']];
    $result->save();

    $this->actingAs($this->user)
        ->get(route('backup-detail', $result->id))
        ->assertOk()
        ->assertSee('Disk full')
        ->assertSee('2 KB')
        ->assertSee('KnownFileCount:</strong>', false)
        ->assertSee('1 day, 5 minutes');
});

test('dashboard shows onboarding without servers', function () {
    $fresh = User::factory()->create();

    $this->actingAs($fresh)->get(route('home'))->assertOk()->assertSee('Add a backup server');
});

test('server edit and notification pages render', function () {
    $this->actingAs($this->user);

    $this->get(route('backup-servers.edit', $this->server))->assertOk()->assertSee($this->server->backup_server_url);
    $this->get(route('notification-channels.index'))->assertOk()->assertSee('Notification Channels');
});

test('a webhook channel can be created and tested from the page', function () {
    Http::fake();
    $this->actingAs($this->user);

    Livewire::test(NotificationChannelsIndex::class)
        ->set('name', 'Ops hook')
        ->set('type', 'webhook')
        ->set('config.url', 'https://hooks.example/x')
        ->set('config.method', 'POST')
        ->set('config.headers', '{"X-Key": "abc"}')
        ->set('notify_on', 'error')
        ->call('save')
        ->assertHasNoErrors();

    $channel = NotificationChannel::firstOrFail();
    expect($channel->config)->toBe(['url' => 'https://hooks.example/x', 'method' => 'POST', 'headers' => ['X-Key' => 'abc']])
        ->and($channel->notify_on)->toBe('error');

    Livewire::test(NotificationChannelsIndex::class)->call('sendTest', $channel->id)->assertSee('Test notification sent');
    Http::assertSent(fn ($r) => $r->url() === 'https://hooks.example/x' && $r->hasHeader('X-Key', 'abc'));
});

test('email channel keeps its stored password when edited without a new one', function () {
    $this->actingAs($this->user);
    $channel = $this->user->notificationChannels()->create([
        'name' => 'Mail', 'type' => 'email',
        'config' => ['to' => 'a@example.com', 'hostname' => 'smtp.example.com', 'password' => 'secret'],
    ]);

    Livewire::test(NotificationChannelsIndex::class)
        ->call('edit', $channel->id)
        ->assertSet('config.password', '')
        ->set('name', 'Mail renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($channel->fresh()->config['password'])->toBe('secret')
        ->and($channel->fresh()->name)->toBe('Mail renamed');
});

test('overdue command notifies once per missed period', function () {
    Http::fake();
    $this->user->notificationChannels()->create([
        'name' => 'Discord', 'type' => 'discord', 'config' => ['webhook_url' => 'https://discord.example/x'],
    ]);

    foreach ([10, 9, 8, 7] as $daysAgo) {
        $r = new BackupResult;
        $r->backup_server_id = $this->server->id;
        $r->extra = ['backup-name' => 'Nightly', 'backup-id' => '5', 'machine-id' => 'm'];
        $r->MainOperation = 'Backup';
        $r->ParsedResult = 'Success';
        $r->Interrupted = false;
        $r->Version = '2.1';
        $r->EndTime = now()->subDays($daysAgo);
        $r->BeginTime = now()->subDays($daysAgo)->subMinutes(5);
        $r->Duration = '00:05:00';
        $r->MessagesActualLength = 0;
        $r->WarningsActualLength = 0;
        $r->ErrorsActualLength = 0;
        $r->BackendStatistics = [];
        $r->save();
    }

    $this->artisan('backups:check-overdue')->assertSuccessful();
    $this->artisan('backups:check-overdue')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_contains($r['content'], 'Overdue') && str_contains($r['content'], 'Nightly'));
});

test('action buttons render valid wire:click arguments', function () {
    $result = new BackupResult;
    $result->backup_server_id = $this->server->id;
    $result->extra = ['backup-name' => 'Quoted', 'backup-id' => "x');alert(1);('", 'machine-id' => 'm'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = 'Success';
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = now();
    $result->BeginTime = now();
    $result->Duration = '00:00:01';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 0;
    $result->BackendStatistics = [];
    $result->save();

    $this->actingAs($this->user);

    $servers = $this->get(route('backup-servers.index'))->assertOk();
    $servers->assertSee("wire:click=\"confirmDelete('{$this->server->id}')\"", false)
        ->assertSee("wire:click=\"confirmRegenerate('{$this->server->id}')\"", false)
        ->assertDontSee('@js(', false);

    $backups = $this->get(route('backups'))->assertOk();
    $backups->assertDontSee('@js(', false)
        ->assertSee("confirmBackupDeletion('{$this->server->id}', 'x\u0027);alert(1);(\u0027')", false)
        ->assertDontSee("alert(1);('", false);
});
