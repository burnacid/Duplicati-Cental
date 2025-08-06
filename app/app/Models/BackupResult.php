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

    public function backupServer()
    {
        return $this->belongsTo(BackupServer::class);
    }
}
