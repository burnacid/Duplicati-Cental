<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-job settings: manual overdue interval, monitoring on/off, snooze.
 * Look these up through an owned BackupServer (BackupServer::ownedBy()).
 */
class BackupJobSetting extends Model
{
    protected $fillable = ['backup_server_id', 'backup_id', 'expected_interval_hours', 'monitored', 'muted_until'];

    protected $casts = [
        'expected_interval_hours' => 'integer',
        'monitored' => 'boolean',
        'muted_until' => 'datetime',
    ];

    public function backupServer(): BelongsTo
    {
        return $this->belongsTo(BackupServer::class);
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }
}
