<?php

namespace App\Jobs;

use App\Services\SystemHealthService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued by the scheduler; when a worker runs it, the health page knows the queue works.
 * Unique, so heartbeats do not pile up while the worker is down.
 */
class QueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public function handle(SystemHealthService $health): void
    {
        $health->beat(SystemHealthService::QUEUE);
    }
}
