<?php

namespace App\Console\Commands;

use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlReportUrlService;
use Illuminate\Console\Command;

class SyncReportUrlsCommand extends Command
{
    protected $signature = 'remote-control:sync-report-urls
        {registration? : Registration id; omit for every claimed instance}
        {--no-cleanup : Only set the managed URL; leave report options set by hand alone}';

    protected $description = 'Set the correct reporting URL on remote-controlled Duplicati instances and remove outdated Central URLs set by hand.';

    public function handle(RemoteControlReportUrlService $service): int
    {
        if (! config('remote_control.enabled')) {
            $this->error('Remote control is disabled.');

            return self::FAILURE;
        }

        $registrations = RemoteControlRegistration::query()
            ->with(['agentState', 'backupServer'])
            ->where('status', RemoteControlRegistration::STATUS_CLAIMED)
            ->when($this->argument('registration'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($registrations->isEmpty()) {
            $this->warn('No claimed instances found.');

            return self::SUCCESS;
        }

        foreach ($registrations as $registration) {
            $summary = $service->sync($registration, ! $this->option('no-cleanup'), logUnchanged: $this->argument('registration') !== null);

            $parts = [$summary['pushed'] ? 'managed URL updated' : 'managed URL correct'];
            if (! $summary['connected']) {
                $parts[] = 'not connected';
            }
            if ($summary['changed'] !== []) {
                $parts[] = 'removed hand-set URLs from '.implode(', ', $summary['changed']);
            }

            $this->line("{$registration->display_name}: ".implode('; ', $parts));
            foreach ($summary['failed'] as $failure) {
                $this->warn("  {$failure}");
            }
        }

        return self::SUCCESS;
    }
}
