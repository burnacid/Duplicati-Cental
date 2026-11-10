<?php

use App\Jobs\QueueHeartbeat;
use App\Livewire\Settings\Health;
use App\Models\User;
use App\Services\SystemHealthService;
use Livewire\Livewire;

function healthCheck(string $key): array
{
    return collect(app(SystemHealthService::class)->checks())->firstWhere('key', $key);
}

beforeEach(function () {
    config(['backups.central.path' => sys_get_temp_dir().'/dc-health-'.bin2hex(random_bytes(4))]);
});

test('guests cannot open the health page', function () {
    $this->get(route('settings.health'))->assertRedirect('/login');
});

test('signed-in users see the health page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.health'))
        ->assertOk()
        ->assertSee('Scheduler')
        ->assertSee('Queue worker')
        ->assertSee('v'.config('app.version'));
});

test('processes count as running only with a recent heartbeat', function () {
    $health = app(SystemHealthService::class);

    expect(healthCheck('scheduler')['status'])->toBe('warning'); // never seen

    $health->beat(SystemHealthService::SCHEDULER);
    expect(healthCheck('scheduler')['status'])->toBe('ok');

    $this->travel(10)->minutes();
    expect(healthCheck('scheduler')['status'])->toBe('error');
});

test('the queue heartbeat job marks the worker as running', function () {
    config(['queue.default' => 'database']);
    expect(healthCheck('queue')['status'])->toBe('warning');

    (new QueueHeartbeat)->handle(app(SystemHealthService::class));

    $check = healthCheck('queue');
    expect($check['status'])->toBe('ok')
        ->and($check['detail'])->toContain('0 jobs waiting');
});

test('the sync queue needs no worker', function () {
    config(['queue.default' => 'sync']);

    expect(healthCheck('queue')['status'])->toBe('ok');
});

test('the remote-control server is off when remote control is disabled', function () {
    config(['remote_control.enabled' => false]);

    expect(healthCheck('websocket')['status'])->toBe('off');
});

test('the database check passes on a migrated database', function () {
    expect(healthCheck('database')['status'])->toBe('ok');
});

test('a scheduled Central backup without any backup is a warning', function () {
    config(['backups.central.enabled' => false]);
    expect(healthCheck('central-backup')['status'])->toBe('off');

    config(['backups.central.enabled' => true]);
    expect(healthCheck('central-backup')['status'])->toBe('warning');
});

test('overall status is the worst check', function () {
    $health = app(SystemHealthService::class);

    expect($health->overall([['status' => 'ok'], ['status' => 'off']]))->toBe('ok')
        ->and($health->overall([['status' => 'ok'], ['status' => 'warning']]))->toBe('warning')
        ->and($health->overall([['status' => 'error'], ['status' => 'warning']]))->toBe('error');
});

test('the health page component renders the checks', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Health::class)
        ->assertSee('Database')
        ->assertSee('Backup of Central');
});
