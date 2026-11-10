<?php

namespace App\Console\Commands;

use App\Models\RemoteControlRegistration;
use App\Services\RemoteLogImportService;
use Illuminate\Console\Command;

class ImportRemoteControlLogsCommand extends Command
{
    protected $signature = 'remote-control:import-logs
        {registration? : Registration id; omit to import from every connected instance}
        {--days=30 : How many days of history to import}';

    protected $description = 'Import backup results from the local logs of connected Duplicati instances (skips results already stored).';

    public function handle(RemoteLogImportService $importer): int
    {
        if (! config('remote_control.enabled')) {
            $this->error('Remote control is disabled.');

            return self::FAILURE;
        }

        $registrations = RemoteControlRegistration::query()
            ->with('agentState')
            ->where('status', RemoteControlRegistration::STATUS_CLAIMED)
            ->when($this->argument('registration'), fn ($q, $id) => $q->whereKey($id))
            ->get()
            ->filter(fn ($r) => $r->agentState?->is_connected);

        if ($registrations->isEmpty()) {
            $this->warn('No connected instances found. The websocket server (remote-control:serve) must be running.');

            return self::SUCCESS;
        }

        foreach ($registrations as $registration) {
            $name = $registration->display_name;
            $this->info("Importing from {$name}...");

            try {
                $stats = $importer->import($registration, (int) $this->option('days'));
                $this->line("  {$stats['jobs']} job(s): {$stats['imported']} imported, {$stats['skipped']} already stored.");
                foreach ($stats['failed_jobs'] as $failure) {
                    $this->warn("  {$failure}");
                }
            } catch (\Throwable $e) {
                $this->error("  {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
