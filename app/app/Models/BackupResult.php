<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'log_lines',
    ];

    protected $casts = [
        'CompactResults' => 'array',
        'TestResults' => 'array',
        'BackendStatistics' => 'array',
        'PartialBackup' => 'boolean',
        'Dryrun' => 'boolean',
        'Interrupted' => 'boolean',
        'EndTime' => 'datetime',
        'BeginTime' => 'datetime',
        'extra' => 'array',
        'log_lines' => 'array',
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
    }

    public function backupServer()
    {
        return $this->belongsTo(BackupServer::class);
    }

    public function getEndTimeAttribute($value)
    {
        return Carbon::parse($value."Z")->setTimezone(config('app.timezone'));
    }

    public function getBeginTimeAttribute($value)
    {
        return Carbon::parse($value."Z")->setTimezone(config('app.timezone'));
    }
}
