<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log lines of a backup result, kept apart from the (frequently scanned) results table.
 */
class BackupResultLog extends Model
{
    protected $primaryKey = 'backup_result_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['backup_result_id', 'log_lines'];

    protected $casts = ['log_lines' => 'array'];

    public function backupResult(): BelongsTo
    {
        return $this->belongsTo(BackupResult::class);
    }
}
