<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-job settings: manual overdue interval, monitoring on/off, snooze, alert rules,
 * heartbeat URL and status page visibility.
 * Look these up through an owned BackupServer (BackupServer::ownedBy()).
 */
class BackupJobSetting extends Model
{
    protected $fillable = [
        'backup_server_id', 'backup_id', 'expected_interval_hours', 'monitored', 'muted_until',
        'alert_size_change_percent', 'alert_duration_factor', 'alert_unchanged_runs', 'alert_quota_free_percent',
        'heartbeat_url', 'on_status_page', 'status_page_name',
    ];

    protected $hidden = ['heartbeat_url'];

    protected $casts = [
        'expected_interval_hours' => 'integer',
        'monitored' => 'boolean',
        'muted_until' => 'datetime',
        'alert_size_change_percent' => 'integer',
        'alert_duration_factor' => 'float',
        'alert_unchanged_runs' => 'integer',
        'alert_quota_free_percent' => 'integer',
        'heartbeat_url' => 'encrypted',
        'on_status_page' => 'boolean',
    ];

    public function backupServer(): BelongsTo
    {
        return $this->belongsTo(BackupServer::class);
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function hasAlertRules(): bool
    {
        return $this->alert_size_change_percent || $this->alert_duration_factor
            || $this->alert_unchanged_runs || $this->alert_quota_free_percent;
    }
}
