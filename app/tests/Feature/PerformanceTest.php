<?php

use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\User;
use App\Services\BackupHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function benchResult(BackupServer $server, string $job, string $status, DateTimeInterface $end, array $logLines = []): BackupResult
{
    $r = new BackupResult;
    $r->backup_server_id = $server->id;
    $r->extra = ['backup-name' => $job, 'backup-id' => 'DB-'.$job, 'machine-id' => 'm'];
    $r->MainOperation = 'Backup';
    $r->ParsedResult = $status;
    $r->Interrupted = false;
    $r->Version = '2.1';
    $r->EndTime = $end;
    $r->BeginTime = $end;
    $r->Duration = '00:01:00';
    $r->MessagesActualLength = 0;
    $r->WarningsActualLength = 0;
    $r->ErrorsActualLength = 0;
    $r->BackendStatistics = ['KnownFileSize' => 1024];
    $r->log_lines = $logLines;
    $r->save();

    return $r;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $this->user->id]);
});

test('log lines are stored outside the results table and still readable', function () {
    $result = benchResult($this->server, 'A', 'Success', now(), ['line one', 'line two']);

    expect(Schema::hasColumn('backup_results', 'log_lines'))->toBeFalse()
        ->and(DB::table('backup_result_logs')->where('backup_result_id', $result->id)->exists())->toBeTrue()
        ->and(BackupResult::find($result->id)->log_lines)->toBe(['line one', 'line two']);

    $this->actingAs($this->user)->get(route('backup-detail', $result->id))->assertSee('line two');

    $result->delete();
    expect(DB::table('backup_result_logs')->count())->toBe(0);
});

test('the latest result per job follows EndTime, not the insert order', function () {
    benchResult($this->server, 'A', 'Fatal', now()->subHour());          // newest run, inserted first
    benchResult($this->server, 'A', 'Success', now()->subDays(10));      // imported older run, higher id

    $job = app(BackupHealthService::class)->jobs($this->user)->first();

    expect($job['last_status']->value)->toBe('Fatal');
});

test('dashboard query count does not grow with the number of jobs', function () {
    $count = function () {
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('home'))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    benchResult($this->server, 'A', 'Success', now()->subDay());
    $few = $count();

    foreach (range(1, 15) as $i) {
        $server = BackupServer::query()->create(['name' => "S{$i}", 'user_id' => $this->user->id]);
        foreach (['X', 'Y', 'Z'] as $job) {
            foreach (range(1, 5) as $d) {
                benchResult($server, $job, 'Success', now()->subDays($d));
            }
        }
    }
    $many = $count();

    expect($many)->toBe($few);
});
