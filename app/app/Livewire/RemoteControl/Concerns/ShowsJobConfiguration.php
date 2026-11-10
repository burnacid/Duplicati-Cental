<?php

namespace App\Livewire\RemoteControl\Concerns;

use App\Support\DuplicatiJobConfig;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * Job details (read-only configuration) and the schedule editor on the machine page.
 */
trait ShowsJobConfiguration
{
    public ?string $detailsBackupId = null;

    /** Job configuration as Duplicati returned it (passwords masked by Duplicati). */
    #[Locked]
    public ?array $jobConfig = null;

    public ?string $detailsError = null;

    public bool $scheduleEnabled = false;

    public string $scheduleEvery = '1';

    public string $scheduleUnit = 'D';

    public string $scheduleTime = '02:00';

    /** @var list<string> */
    public array $scheduleDays = [];

    public ?string $scheduleMessage = null;

    public function showJob(string $backupId): void
    {
        $this->detailsBackupId = $backupId;
        $this->jobConfig = null;
        $this->detailsError = null;
        $this->scheduleMessage = null;
        $this->resetValidation();
        $this->request('config', 'GET', '/api/v1/backup/'.rawurlencode($backupId));

        Flux::modal('job-details')->show();
    }

    public function saveSchedule(): void
    {
        if ($this->jobConfig === null || $this->detailsBackupId === null || $this->isWaitingFor('save')) {
            return;
        }

        $this->validate([
            'scheduleEnabled' => ['boolean'],
            'scheduleEvery' => ['required_if:scheduleEnabled,true', 'integer', 'min:1', 'max:1000'],
            'scheduleUnit' => ['required', Rule::in(array_keys(DuplicatiJobConfig::REPEAT_UNITS))],
            'scheduleTime' => ['required_if:scheduleEnabled,true', 'date_format:H:i'],
            'scheduleDays' => ['array'],
            'scheduleDays.*' => [Rule::in(DuplicatiJobConfig::WEEKDAYS)],
        ], [], ['scheduleEvery' => 'interval', 'scheduleTime' => 'time']);

        $body = DuplicatiJobConfig::withSchedule(
            $this->jobConfig, $this->scheduleEnabled, (int) $this->scheduleEvery, $this->scheduleUnit, $this->scheduleTime, $this->scheduleDays,
        );

        $this->scheduleMessage = 'Saving…';
        $this->request('save', 'PUT', '/api/v1/backup/'.rawurlencode($this->detailsBackupId), $body);
    }

    protected function onJobConfigResponse(string $key, array $body, ?string $error): void
    {
        if ($key === 'config') {
            if ($error !== null) {
                $this->detailsError = $error;

                return;
            }

            $this->jobConfig = $body;
            $form = DuplicatiJobConfig::scheduleForm($body);
            $this->scheduleEnabled = $form['enabled'];
            $this->scheduleEvery = (string) $form['every'];
            $this->scheduleUnit = $form['unit'];
            $this->scheduleTime = $form['time'];
            $this->scheduleDays = $form['days'];

            return;
        }

        // 'save'
        if ($error !== null) {
            $this->scheduleMessage = 'Not saved: '.$error;

            return;
        }

        $this->scheduleMessage = 'Schedule saved.';
        if ($this->detailsBackupId !== null) {
            // Show what Duplicati stored, and refresh the job list (next run).
            $this->request('config', 'GET', '/api/v1/backup/'.rawurlencode($this->detailsBackupId));
            $this->refreshBackups();
        }
    }

    public function jobSummary(): ?array
    {
        return $this->jobConfig === null ? null : DuplicatiJobConfig::summarize($this->jobConfig);
    }
}
