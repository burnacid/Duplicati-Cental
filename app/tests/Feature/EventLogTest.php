<?php

use App\Jobs\PingHeartbeat;
use App\Livewire\EventLog\Index as EventLogIndex;
use App\Models\BackupJobSetting;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\EventLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function eventReportPayload(string $operation = 'Backup', string $status = 'Success'): array
{
    return [
        'Data' => ['MainOperation' => $operation, 'ParsedResult' => $status, 'EndTime' => now()->toIso8601String(), 'BeginTime' => now()->subMinutes(3)->toIso8601String()],
        'Extra' => ['machine-id' => 'm', 'machine-name' => 'M', 'backup-name' => 'Docs', 'backup-id' => 'DB-1'],
    ];
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $this->user->id]);
    $this->url = "/api/backup-servers/{$this->server->id}/{$this->server->api_key}/backup-result";
});

test('a stored report is logged with a link to the result', function () {
    $this->postJson($this->url, eventReportPayload())->assertCreated();

    $event = EventLog::query()->sole();
    expect($event)
        ->user_id->toBe($this->user->id)
        ->backup_server_id->toBe($this->server->id)
        ->category->toBe(EventLog::CATEGORY_REPORT)
        ->level->toBe(EventLog::LEVEL_INFO)
        ->message->toContain('"Docs" stored: Success')
        ->and($event->context['backup_result_id'])->toBe(BackupResult::query()->value('id'));
});

test('a failed backup report is logged as a warning', function () {
    $this->postJson($this->url, eventReportPayload('Backup', 'Fatal'))->assertCreated();

    expect(EventLog::query()->sole()->level)->toBe(EventLog::LEVEL_WARNING);
});

test('ignored list operations are logged', function () {
    $this->postJson($this->url, eventReportPayload('List'))->assertOk();

    expect(EventLog::query()->sole()->message)->toContain('ignored');
});

test('a rejected JSON report is logged with the validation errors', function () {
    $this->postJson($this->url, ['Data' => ['MainOperation' => 'Backup']])->assertStatus(422);

    $event = EventLog::query()->sole();
    expect($event->level)->toBe(EventLog::LEVEL_ERROR)
        ->and($event->message)->toContain('required fields are missing')
        ->and($event->context['errors'])->toContain('The extra field is required.')
        ->and($event->context['received_keys'])->toBe(['Data']);
});

test('a form-encoded report explains how to switch Duplicati to JSON', function () {
    $this->post($this->url, ['message' => 'Duplicati Backup report for Docs'])->assertStatus(422);

    $event = EventLog::query()->sole();
    expect($event->message)->toContain('--send-http-result-output-format=Json');
});

test('a wrong API key is logged for the server owner without storing the key', function () {
    $this->postJson("/api/backup-servers/{$this->server->id}/wrong-key/backup-result", eventReportPayload())->assertNotFound();

    $event = EventLog::query()->sole();
    expect($event->user_id)->toBe($this->user->id)
        ->and($event->message)->toContain('wrong API key')
        ->and(json_encode($event->context))->not->toContain('wrong-key');
    expect(BackupResult::query()->count())->toBe(0);
});

test('a report for an unknown server is not logged for anyone', function () {
    $this->postJson('/api/backup-servers/00000000-0000-0000-0000-000000000000/key/backup-result', eventReportPayload())->assertNotFound();

    expect(EventLog::query()->count())->toBe(0);
});

test('notification deliveries are logged per channel', function () {
    config(['mail.default' => 'array']);
    Http::fake(['https://broken.example/*' => Http::response('nope', 500)]);
    $this->user->notificationChannels()->create(['name' => 'Mail', 'type' => 'email', 'config' => ['to' => 'ops@example.com'], 'is_active' => true, 'notify_on' => 'all']);
    $this->user->notificationChannels()->create(['name' => 'Hook', 'type' => 'webhook', 'config' => ['url' => 'https://broken.example/hook'], 'is_active' => true, 'notify_on' => 'all']);

    app(NotificationService::class)->send($this->user, 'Backup failed', 'Backup failed');

    $events = EventLog::query()->where('category', EventLog::CATEGORY_NOTIFICATION)->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->level)->toBe(EventLog::LEVEL_INFO)
        ->and($events[0]->message)->toBe('Sent to Mail: Backup failed')
        ->and($events[1]->level)->toBe(EventLog::LEVEL_ERROR)
        ->and($events[1]->message)->toBe('Could not send to Hook: Backup failed');
});

test('heartbeat pings are logged', function () {
    Http::fake(['https://hc.example/*' => Http::response('', 200), 'https://down.example/*' => Http::response('', 503)]);
    $this->postJson($this->url, eventReportPayload())->assertCreated();
    $result = BackupResult::query()->sole();
    $setting = BackupJobSetting::query()->create(['backup_server_id' => $this->server->id, 'backup_id' => 'DB-1', 'heartbeat_url' => 'https://hc.example/ping/1']);

    (new PingHeartbeat($result->id))->handle();
    expect(EventLog::query()->where('category', EventLog::CATEGORY_HEARTBEAT)->sole()->message)->toContain('sent (HTTP 200)');

    $setting->update(['heartbeat_url' => 'https://down.example/ping/1']);
    expect(fn () => (new PingHeartbeat($result->id))->handle())->toThrow(Exception::class);
    expect(EventLog::query()->where('category', EventLog::CATEGORY_HEARTBEAT)->latest('id')->first())
        ->level->toBe(EventLog::LEVEL_ERROR)
        ->message->toContain('HTTP 503');
});

test('the event log page lists and filters the user\'s events', function () {
    $this->postJson($this->url, eventReportPayload())->assertCreated();
    $this->postJson($this->url, ['Data' => []])->assertStatus(422);

    $this->actingAs($this->user)->get(route('event-log'))
        ->assertOk()
        ->assertSee('"Docs" stored: Success')
        ->assertSee('Report rejected');

    Livewire::actingAs($this->user)->test(EventLogIndex::class)
        ->set('level', 'error')
        ->assertSee('Report rejected')
        ->assertDontSee('stored: Success');
});

test('old events are pruned daily', function () {
    config(['backups.event_log_retention_days' => 30]);
    EventLog::query()->create(['user_id' => $this->user->id, 'category' => 'report', 'level' => 'info', 'message' => 'old'])
        ->forceFill(['created_at' => now()->subDays(31)])->save();
    EventLog::query()->create(['user_id' => $this->user->id, 'category' => 'report', 'level' => 'info', 'message' => 'new']);

    Artisan::call('schedule:test', ['--name' => 'prune-old-records']);

    expect(EventLog::query()->pluck('message')->all())->toBe(['new']);
});
