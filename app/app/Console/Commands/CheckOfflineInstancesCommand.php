<?php

namespace App\Console\Commands;

use App\Models\RemoteControlRegistration;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Alerts once when a claimed remote-control instance stops checking in, and again when
 * it is back. Runs every 5 minutes (routes/console.php). Snoozed servers are skipped.
 */
class CheckOfflineInstancesCommand extends Command
{
    protected $signature = 'remote-control:check-offline';

    protected $description = 'Notify when remote-controlled Duplicati instances go offline or come back';

    public function handle(NotificationService $notifications): int
    {
        $minutes = (int) config('remote_control.offline_alert_minutes');
        if (! config('remote_control.enabled') || $minutes <= 0) {
            return self::SUCCESS;
        }

        $registrations = RemoteControlRegistration::query()
            ->with(['agentState', 'backupServer', 'claimedByUser'])
            ->where('status', RemoteControlRegistration::STATUS_CLAIMED)
            ->whereNotNull('claimed_by_user_id')
            ->whereHas('agentState', fn ($q) => $q->whereNotNull('last_seen_at'))
            ->get();

        $offline = $online = 0;
        foreach ($registrations as $registration) {
            $state = $registration->agentState;
            $user = $registration->claimedByUser;
            if (! $user) {
                continue;
            }

            $snoozed = (bool) $registration->backupServer?->isMuted();
            $gone = $state->last_seen_at->lt(now()->subMinutes($minutes));

            if ($gone && $state->offline_notified_at === null) {
                if (! $snoozed) {
                    $notifications->notifyInstanceOffline($user, $registration, $state->last_seen_at);
                    $offline++;
                }
                // Also marked while snoozed, so resuming does not send an old alert.
                $state->forceFill(['offline_notified_at' => now()])->save();
            } elseif ($state->is_connected && $state->offline_notified_at !== null) {
                if (! $snoozed) {
                    $notifications->notifyInstanceOnline($user, $registration, $state->offline_notified_at);
                    $online++;
                }
                $state->forceFill(['offline_notified_at' => null])->save();
            }
        }

        $this->info("{$offline} offline and {$online} back-online alert(s) sent.");

        return self::SUCCESS;
    }
}
