<?php

use App\Enums\BackupStatus;
use App\Livewire\Concerns\FormatsBackupValues;
use App\Services\BackupHealthService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

test('error and fatal are both failures shown in red', function () {
    expect(BackupStatus::fromResult('Error')->isFailure())->toBeTrue()
        ->and(BackupStatus::fromResult('Fatal')->isFailure())->toBeTrue()
        ->and(BackupStatus::fromResult('Error')->color())->toBe('red')
        ->and(BackupStatus::fromResult('Fatal')->color())->toBe('red')
        ->and(BackupStatus::fromResult('Success')->isFailure())->toBeFalse();
});

test('worst status wins', function () {
    expect(BackupStatus::worstOf(['Success', 'Warning', 'Success']))->toBe(BackupStatus::Warning)
        ->and(BackupStatus::worstOf(['Success', 'Error']))->toBe(BackupStatus::Error)
        ->and(BackupStatus::worstOf([]))->toBe(BackupStatus::None);
});

test('durations including days are formatted correctly', function () {
    $formatter = new class
    {
        use FormatsBackupValues;

        public function duration($d)
        {
            return $this->formatDuration($d);
        }

        public function size($b)
        {
            return $this->formatSize($b);
        }
    };

    expect($formatter->duration('00:05:12.1234567'))->toBe('5 minutes, 12 seconds')
        ->and($formatter->duration('1.02:03:04'))->toBe('1 day, 2 hours, 3 minutes, 4 seconds')
        ->and($formatter->duration('00:00:00'))->toBe('0 seconds')
        ->and($formatter->size(1536))->toBe('1.5 KB')
        ->and($formatter->size(null))->toBe('0 B');
});

test('expected interval is the median gap', function () {
    $service = new BackupHealthService;
    $base = Carbon::parse('2026-01-01 00:00:00');

    $times = collect([0, 24, 48, 73, 96])->map(fn ($h) => $base->copy()->addHours($h));

    expect($service->expectedInterval($times))->toBe(24 * 3600)
        ->and($service->expectedInterval($times->take(2)))->toBeNull();
});

test('a daily backup is overdue after about two missed days', function () {
    $service = new BackupHealthService;
    $day = 24 * 3600;

    expect($service->isOverdue(now()->subHours(30), $day))->toBeFalse()
        ->and($service->isOverdue(now()->subHours(50), $day))->toBeTrue()
        ->and($service->isOverdue(null, $day))->toBeFalse();
});

test('without a known schedule the fallback applies', function () {
    config(['backups.overdue_fallback_hours' => 168]);
    $service = new BackupHealthService;

    expect($service->isOverdue(now()->subDays(3), null))->toBeFalse()
        ->and($service->isOverdue(now()->subDays(8), null))->toBeTrue();
});
