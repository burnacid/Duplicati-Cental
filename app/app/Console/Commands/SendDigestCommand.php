<?php

namespace App\Console\Commands;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\DigestService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendDigestCommand extends Command
{
    protected $signature = 'backups:send-digest {frequency : daily or weekly}';

    protected $description = 'Send the daily or weekly backup summary to the channels that asked for it.';

    public function handle(DigestService $digests, NotificationService $notifications): int
    {
        $frequency = $this->argument('frequency');
        if (! in_array($frequency, [NotificationChannel::DIGEST_DAILY, NotificationChannel::DIGEST_WEEKLY], true)) {
            $this->error('Frequency must be "daily" or "weekly".');

            return self::INVALID;
        }

        $sent = 0;
        User::query()
            ->whereHas('notificationChannels', fn ($q) => $q->where('is_active', true)->where('digest', $frequency))
            ->each(function (User $user) use ($digests, $notifications, $frequency, &$sent) {
                if ($digest = $digests->build($user, $frequency)) {
                    $sent += $notifications->notifyDigest($user, $frequency, $digest);
                }
            });

        $this->info("Sent {$sent} {$frequency} summary message(s).");

        return self::SUCCESS;
    }
}
