<?php

namespace App\Livewire;

use App\Services\BackupHealthService;
use Livewire\Component;

/**
 * Invisible component in the app layout: puts the number of jobs that need attention
 * in the browser tab title and on the installed app icon (Badging API).
 */
class FailingJobsBadge extends Component
{
    public function render(BackupHealthService $health)
    {
        $count = $health->jobs(auth()->user())
            ->reject(fn ($job) => $job['is_muted'])
            ->filter(fn ($job) => $job['is_overdue'] || $job['last_status']->isFailure())
            ->count();

        return view('livewire.failing-jobs-badge', ['count' => $count]);
    }
}
