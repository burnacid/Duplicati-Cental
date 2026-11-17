<?php

namespace App\Jobs;

use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlReportUrlService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sets the correct reporting URL on one instance and removes hand-set Central URLs
 * (started from the machine page or the Remote Control page). The outcome is in the event log.
 */
class SyncReportUrls implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public string $registrationId) {}

    public function uniqueId(): string
    {
        return $this->registrationId;
    }

    public function handle(RemoteControlReportUrlService $service): void
    {
        if (! config('remote_control.enabled')) {
            return;
        }

        $registration = RemoteControlRegistration::query()
            ->where('status', RemoteControlRegistration::STATUS_CLAIMED)
            ->find($this->registrationId);

        if ($registration) {
            $service->sync($registration, logUnchanged: true);
        }
    }
}
