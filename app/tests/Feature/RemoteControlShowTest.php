<?php

use App\Livewire\RemoteControl\Show;
use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use App\Models\User;
use Livewire\Livewire;

test('mounting as the owner creates pending backups and progress commands', function () {
    $owner = User::factory()->create();
    $registration = claimedRegistrationFor($owner);

    $this->actingAs($owner);

    Livewire::test(Show::class, ['id' => (string) $registration->id]);

    $commands = RemoteControlCommand::query()
        ->where('remote_control_registration_id', $registration->id)
        ->get();

    expect($commands)->toHaveCount(2)
        ->and($commands->pluck('path')->sort()->values()->all())->toBe(['/api/v1/backups', '/api/v1/progressstate']);
});

test('mounting with another users registration id 404s', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherRegistration = claimedRegistrationFor($otherUser);

    $this->actingAs($owner);

    $this->get(route('remote-control.show', $otherRegistration->id))
        ->assertNotFound();
});

test('runBackup creates a pending run command and is a no-op while one is in flight', function () {
    $owner = User::factory()->create();
    $registration = claimedRegistrationFor($owner);

    $this->actingAs($owner);

    $component = Livewire::test(Show::class, ['id' => (string) $registration->id])
        ->call('runBackup', 'job-1')
        ->call('runBackup', 'job-1');

    $runCommands = RemoteControlCommand::query()
        ->where('remote_control_registration_id', $registration->id)
        ->where('method', 'POST')
        ->get();

    expect($runCommands)->toHaveCount(1)
        ->and($runCommands->first()->path)->toBe('/api/v1/backup/job-1/run?skipQueue=false')
        ->and($runCommands->first()->requested_by_user_id)->toBe($owner->id);

    expect($component->get('tasks'))->toHaveKey('job-1')
        ->and($component->get('tasks')['job-1']['phase'])->toBe('sending');
});

/** Simulate the websocket server delivering a response for a command. */
function completeCommand(string $commandId, int $code, array $body): void
{
    RemoteControlCommand::query()->whereKey($commandId)->update([
        'status' => RemoteControlCommand::STATUS_COMPLETED,
        'response_status_code' => $code,
        'response_body' => json_encode($body),
        'completed_at' => now(),
    ]);
}

test('a run is followed until Duplicati reports the task as failed', function () {
    $owner = User::factory()->create();
    $registration = claimedRegistrationFor($owner);
    $this->actingAs($owner);

    $component = Livewire::test(Show::class, ['id' => (string) $registration->id]);
    completeCommand($component->get('backupsCommandId'), 200, [['Backup' => ['ID' => '1', 'Name' => 'Test']]]);
    $component->call('runBackup', '1');

    // Duplicati accepts the run and returns its task id.
    completeCommand($component->get('tasks')['1']['command_id'], 200, ['Status' => 'OK', 'ID' => 7]);
    $component->call('poll');
    expect($component->get('tasks')['1'])->phase->toBe('waiting')->task_id->toBe(7);

    // Next tick asks for the task status.
    $component->call('poll');
    $statusCommand = RemoteControlCommand::find($component->get('tasks')['1']['command_id']);
    expect($statusCommand->path)->toBe('/api/v1/task/7');

    completeCommand($statusCommand->id, 200, ['Status' => 'Running', 'ID' => 7]);
    $component->call('poll');
    expect($component->get('tasks')['1']['phase'])->toBe('running');

    $component->call('poll');
    completeCommand($component->get('tasks')['1']['command_id'], 200, [
        'Status' => 'Failed', 'ID' => 7,
        'ErrorMessage' => 'Found 3 files that are missing from the remote storage, please run repair',
    ]);
    $component->call('poll')
        ->assertSee('Failed')
        ->assertSee('please run repair');

    expect($component->get('tasks')['1']['phase'])->toBe('failed');

    // A finished task can be dismissed and the job can be started again.
    $component->call('dismissTask', '1');
    expect($component->get('tasks'))->not->toHaveKey('1');
});

test('repair sends the repair endpoint and reports errors from the instance', function () {
    $owner = User::factory()->create();
    $registration = claimedRegistrationFor($owner);
    $this->actingAs($owner);

    $component = Livewire::test(Show::class, ['id' => (string) $registration->id])->call('repairBackup', '1');

    $command = RemoteControlCommand::find($component->get('tasks')['1']['command_id']);
    expect($command->method)->toBe('POST')->and($command->path)->toBe('/api/v1/backup/1/repair');

    completeCommand($command->id, 500, ['Error' => 'Backup is busy']);
    $component->call('poll');

    expect($component->get('tasks')['1'])->phase->toBe('error')->message->toBe('Backup is busy');
});

test('a command that times out marks the task as not started', function () {
    $owner = User::factory()->create();
    $registration = claimedRegistrationFor($owner);
    $this->actingAs($owner);

    $component = Livewire::test(Show::class, ['id' => (string) $registration->id])->call('runBackup', '1');

    RemoteControlCommand::query()->whereKey($component->get('tasks')['1']['command_id'])->update([
        'status' => RemoteControlCommand::STATUS_TIMEOUT,
        'error_message' => 'No connection available before timeout.',
    ]);
    $component->call('poll');

    expect($component->get('tasks')['1'])->phase->toBe('error')->message->toBe('No connection available before timeout.');
});

test('the last stored result is shown per job, matching DB-prefixed ids', function () {
    $owner = User::factory()->create();
    $registration = claimedRegistrationFor($owner);
    $server = \App\Models\BackupServer::query()->create(['name' => 'RC', 'user_id' => $owner->id]);
    $registration->update(['backup_server_id' => $server->id]);

    $result = new \App\Models\BackupResult();
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => 'Test', 'backup-id' => 'DB-1', 'machine-id' => $registration->machine_id];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = 'Fatal';
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = now()->subMinute();
    $result->BeginTime = now()->subMinutes(2);
    $result->Duration = '00:00:05';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 1;
    $result->BackendStatistics = [];
    $result->save();

    $this->actingAs($owner);
    $component = Livewire::test(Show::class, ['id' => (string) $registration->id]);
    completeCommand($component->get('backupsCommandId'), 200, [['Backup' => ['ID' => '1', 'Name' => 'Test']]]);

    $component->call('poll')
        ->assertSee('Last result:')
        ->assertSee('Fatal')
        ->assertSee(route('backup-detail', $result->id), false);
});

test('poll decodes a completed backups command into the backups property', function () {
    $owner = User::factory()->create();
    $registration = claimedRegistrationFor($owner);

    $this->actingAs($owner);

    $component = Livewire::test(Show::class, ['id' => (string) $registration->id]);

    $backupsCommandId = $component->get('backupsCommandId');

    RemoteControlCommand::query()->whereKey($backupsCommandId)->update([
        'status' => RemoteControlCommand::STATUS_COMPLETED,
        'response_status_code' => 200,
        'response_body' => json_encode([['id' => 'job-1', 'name' => 'Daily Backup']]),
        'completed_at' => now(),
    ]);

    $component->call('poll');

    expect($component->get('backups'))->toBe([['id' => 'job-1', 'name' => 'Daily Backup', 'description' => null, 'next_run' => null]])
        ->and($component->get('backupsCommandId'))->toBeNull();
});

test('poll understands the nested PascalCase list Duplicati returns', function () {
    $owner = User::factory()->create();
    $registration = claimedRegistrationFor($owner);

    $this->actingAs($owner);

    $component = Livewire::test(Show::class, ['id' => (string) $registration->id]);

    RemoteControlCommand::query()->whereKey($component->get('backupsCommandId'))->update([
        'status' => RemoteControlCommand::STATUS_COMPLETED,
        'response_status_code' => 200,
        'response_body' => json_encode([
            ['Backup' => ['ID' => '3', 'Name' => 'Photos', 'Description' => 'NAS photos'], 'Schedule' => ['Time' => '2026-11-10T02:00:00Z']],
            ['Backup' => ['Name' => 'Broken entry without id'], 'Schedule' => null],
        ]),
        'completed_at' => now(),
    ]);

    $component->call('poll')
        ->assertSee('Photos')
        ->assertSee('NAS photos');

    expect($component->get('backups'))->toBe([
        ['id' => '3', 'name' => 'Photos', 'description' => 'NAS photos', 'next_run' => '2026-11-10T02:00:00Z'],
    ]);
});
