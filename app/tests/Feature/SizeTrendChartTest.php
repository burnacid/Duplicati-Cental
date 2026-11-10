<?php

use App\Livewire\BackupResults;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\User;
use App\Support\SizeTrendChart;
use Livewire\Livewire;

function sizedResult(BackupServer $server, int $daysAgo, int $source, int $backup, string $operation = 'Backup'): void
{
    $result = new BackupResult();
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => 'Documents', 'backup-id' => 'DB-1', 'machine-id' => 'm1'];
    $result->MainOperation = $operation;
    $result->ParsedResult = 'Success';
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = now()->utc()->subDays($daysAgo)->format('Y-m-d\TH:i:s\Z');
    $result->BeginTime = now()->utc()->subDays($daysAgo)->format('Y-m-d\TH:i:s\Z');
    $result->Duration = '00:05:00';
    $result->SizeOfExaminedFiles = $source;
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 0;
    $result->BackendStatistics = ['KnownFileSize' => $backup];
    $result->save();
}

test('the chart scale uses round binary units', function () {
    $gb = 1024 ** 3;
    $chart = new SizeTrendChart([
        ['t' => 1_700_000_000, 'source' => 90 * $gb, 'backup' => 40 * $gb],
        ['t' => 1_700_086_400, 'source' => 110 * $gb, 'backup' => 45 * $gb],
    ]);

    expect(array_column($chart->yTicks(), 'label'))->toBe(['0 B', '50 GB', '100 GB', '150 GB'])
        ->and($chart->path('source'))->toStartWith('M64 ')
        ->and($chart->xTicks())->toHaveCount(5);

    expect((new SizeTrendChart([]))->isEmpty())->toBeTrue();
});

test('the size trend shows only when one job is selected, for backup runs in the period', function () {
    $user = User::factory()->create();
    $server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $user->id]);
    sizedResult($server, 100, 500, 200);
    sizedResult($server, 20, 1000, 400);
    sizedResult($server, 10, 1500, 600);
    sizedResult($server, 5, 9999, 9999, 'Restore');
    $this->actingAs($user);

    Livewire::test(BackupResults::class)->assertViewHas('sizeTrend', null)->assertDontSee('Size trend');

    Livewire::test(BackupResults::class, ['selectedServerId' => $server->id, 'selectedBackupId' => 'DB-1'])
        ->assertSee('Size trend')
        ->assertSee('source +50% in this period')
        ->assertViewHas('sizeTrend', fn ($points) => array_column($points, 'source') === [1000, 1500])
        ->set('chartDays', 365)
        ->assertViewHas('sizeTrend', fn ($points) => count($points) === 3);
});

test('the size trend does not show another user\'s job', function () {
    $owner = User::factory()->create();
    $server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $owner->id]);
    sizedResult($server, 1, 1000, 400);

    $this->actingAs(User::factory()->create());

    Livewire::test(BackupResults::class, ['selectedServerId' => $server->id, 'selectedBackupId' => 'DB-1'])
        ->assertViewHas('sizeTrend', [])
        ->assertSee('No backup runs with size information');
});
