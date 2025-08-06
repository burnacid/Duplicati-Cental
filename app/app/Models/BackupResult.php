<?php

namespace App\Models;

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
}
