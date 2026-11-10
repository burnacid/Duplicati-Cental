<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\BackupStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BackupResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'DeletedFiles',
        'DeletedFolders',
        'ModifiedFiles',
        'ExaminedFiles',
        'OpenedFiles',
        'AddedFiles',
        'SizeOfModifiedFiles',
        'SizeOfAddedFiles',
        'SizeOfExaminedFiles',
        'SizeOfOpenedFiles',
        'NotProcessedFiles',
        'AddedFolders',
        'TooLargeFiles',
        'FilesWithError',
        'ModifiedFolders',
        'ModifiedSymlinks',
        'AddedSymlinks',
        'DeletedSymlinks',
        'PartialBackup',
        'Dryrun',
        'MainOperation',
        'CompactResults',
        'TestResults',
        'ParsedResult',
        'Interrupted',
        'Version',
        'EndTime',
        'BeginTime',
        'Duration',
        'MessagesActualLength',
        'WarningsActualLength',
        'ErrorsActualLength',
        'BackendStatistics',
        'backup_name',
        'backup_id',
        'extra',
        'RestoredFiles',
        'SizeOfRestoredFiles',
        'RestoredFolders',
        'RestoredSymlinks',
        'PatchedFiles',
    ];

    /** Log lines set on this instance, written to backup_result_logs after save. */
    private ?array $pendingLogLines = null;

    protected $casts = [
        'CompactResults' => 'array',
        'VacuumResults' => 'array',
        'DeleteResults' => 'array',
        'RepairResults' => 'array',
        'TestResults' => 'array',
        'BackendStatistics' => 'array',
        'PartialBackup' => 'boolean',
        'Dryrun' => 'boolean',
        'Interrupted' => 'boolean',
        'EndTime' => UtcDateTime::class,
        'BeginTime' => UtcDateTime::class,
        'extra' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (isset($model->extra['backup-name'])) {
                $model->backup_name = $model->extra['backup-name'];
            }
            if (isset($model->extra['backup-id'])) {
                $model->backup_id = $model->extra['backup-id'];
            }
        });

        static::saved(function (self $model) {
            if ($model->pendingLogLines !== null) {
                BackupResultLog::query()->updateOrCreate(
                    ['backup_result_id' => $model->id],
                    ['log_lines' => $model->pendingLogLines],
                );
                $model->unsetRelation('logRecord');
                $model->pendingLogLines = null;
            }
        });
    }

    public function logRecord(): HasOne
    {
        return $this->hasOne(BackupResultLog::class);
    }

    /**
     * Log lines live in backup_result_logs; read lazily, written on save.
     */
    protected function logLines(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pendingLogLines ?? $this->logRecord?->log_lines ?? [],
            set: function ($value) {
                $this->pendingLogLines = is_array($value) ? array_values($value) : [];

                return [];
            },
        );
    }

    public function scopeForUser(Builder $query, User|string|null $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        // whereIn (not whereHas/EXISTS) so the backup_server_id indexes can be used.
        return $query->whereIn(
            $query->qualifyColumn('backup_server_id'),
            BackupServer::query()->select('id')->where('user_id', $userId),
        );
    }

    public function backupServer()
    {
        return $this->belongsTo(BackupServer::class);
    }

    public function getMachineIdAttribute(): ?string
    {
        return data_get($this->extra, 'machine-id');
    }

    public function getStatusAttribute(): BackupStatus
    {
        return BackupStatus::fromResult($this->ParsedResult);
    }
}
