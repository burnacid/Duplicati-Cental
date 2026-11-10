<?php

use App\Livewire\Settings\StatusPage;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\User;
use Livewire\Livewire;

function statusPageResult(BackupServer $server, string $name, string $status): BackupResult
{
    $result = new BackupResult;
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => $name, 'backup-id' => 'DB-'.$name, 'machine-id' => 'secret-machine'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = $status;
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = now()->subHour();
    $result->BeginTime = now()->subHours(2);
    $result->Duration = '00:05:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 0;
    $result->SizeOfExaminedFiles = 123456789;
    $result->BackendStatistics = ['KnownFileSize' => 987654321];
    $result->log_lines = ['C:\\Users\\secret\\Documents'];
    $result->save();

    return $result;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'Internal-Server-Name', 'user_id' => $this->user->id]);
    statusPageResult($this->server, 'Website', 'Success');
    statusPageResult($this->server, 'Database', 'Error');
    statusPageResult($this->server, 'Private', 'Success');

    $this->server->jobSettings()->create(['backup_id' => 'DB-Website', 'on_status_page' => true, 'status_page_name' => 'Public website']);
    $this->server->jobSettings()->create(['backup_id' => 'DB-Database', 'on_status_page' => true]);
});

test('the status page is off until it is turned on', function () {
    $this->get('/status/'.str_repeat('a', 48))->assertNotFound();
    $this->get('/status/short')->assertNotFound();

    $this->actingAs($this->user);
    Livewire::test(StatusPage::class)->call('enable');

    expect($this->user->fresh()->hasStatusPage())->toBeTrue();
});

test('the page shows only chosen jobs, without servers, sizes or logs', function () {
    $this->user->forceFill(['status_page_token' => str_repeat('t', 48), 'status_page_title' => 'ACME backups'])->save();

    $response = $this->get('/status/'.str_repeat('t', 48))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('ACME backups')
        ->assertSee('Public website')
        ->assertSee('Database')
        ->assertSee('data-overall="down"', false)
        ->assertDontSee('Private')
        ->assertDontSee('Internal-Server-Name')
        ->assertDontSee('secret-machine')
        ->assertDontSee('secret\\Documents', false)
        ->assertDontSee('117.7 MB')
        ->assertDontSee('/backup-detail/', false);

    expect(substr_count($response->getContent(), 'data-state="down"'))->toBe(1)
        ->and(substr_count($response->getContent(), 'data-state="operational"'))->toBe(1);
});

test('a new link replaces the old one, and turning it off removes it', function () {
    $this->actingAs($this->user);
    $component = Livewire::test(StatusPage::class)->call('enable');
    $old = $this->user->fresh()->status_page_token;

    $component->call('regenerate');
    $new = $this->user->fresh()->status_page_token;
    expect($new)->not->toBe($old);

    auth()->logout();
    $this->get('/status/'.$old)->assertNotFound();
    $this->get('/status/'.$new)->assertOk();

    $this->actingAs($this->user);
    Livewire::test(StatusPage::class)->call('disable');
    auth()->logout();
    $this->get('/status/'.$new)->assertNotFound();
});

test('another user\'s jobs never appear on a status page', function () {
    $other = User::factory()->create();
    $otherServer = BackupServer::query()->create(['name' => 'Other', 'user_id' => $other->id]);
    statusPageResult($otherServer, 'TheirJob', 'Success');
    $otherServer->jobSettings()->create(['backup_id' => 'DB-TheirJob', 'on_status_page' => true]);

    $this->user->forceFill(['status_page_token' => str_repeat('u', 48)])->save();

    $this->get('/status/'.str_repeat('u', 48))->assertOk()->assertDontSee('TheirJob');
});

test('the settings page shows the link and the jobs on it', function () {
    $this->user->forceFill(['status_page_token' => str_repeat('v', 48)])->save();

    $this->actingAs($this->user)->get(route('settings.status-page'))
        ->assertOk()
        ->assertSee(route('status-page', str_repeat('v', 48)))
        ->assertSee('Public website');
});
