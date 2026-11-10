<?php

namespace App\Services;

use App\Models\BackupResult;
use App\Models\BackupServer;

/**
 * Turns Duplicati's result JSON ("Data" in a report, or a "Result" log entry)
 * into a BackupResult. Shared by the report API and the remote log import.
 */
class BackupResultFactory
{
    /** Counters/sub-results Duplicati sends for some operations. Missing values become null. */
    private const OPTIONAL_DATA_FIELDS = [
        'DeletedFiles', 'DeletedFolders', 'ModifiedFiles', 'ExaminedFiles', 'OpenedFiles', 'AddedFiles',
        'SizeOfModifiedFiles', 'SizeOfAddedFiles', 'SizeOfExaminedFiles', 'SizeOfOpenedFiles',
        'NotProcessedFiles', 'AddedFolders', 'TooLargeFiles', 'FilesWithError', 'ModifiedFolders',
        'ModifiedSymlinks', 'AddedSymlinks', 'DeletedSymlinks', 'PartialBackup', 'Dryrun',
        'RestoredFiles', 'SizeOfRestoredFiles', 'RestoredFolders', 'RestoredSymlinks', 'PatchedFiles',
        'CompactResults', 'VacuumResults', 'DeleteResults', 'RepairResults', 'TestResults',
    ];

    /**
     * Build (not save) a result.
     *
     * @param  array<string, mixed>  $data  Duplicati result object
     * @param  array<string, mixed>  $extra  backup-name, backup-id, machine-id, machine-name, ...
     * @param  array<int, mixed>  $logLines
     */
    public function make(BackupServer $server, array $data, array $extra, array $logLines = []): BackupResult
    {
        $result = new BackupResult;
        $result->backup_server_id = $server->id;
        $result->extra = $extra;
        $result->log_lines = $logLines;

        foreach (self::OPTIONAL_DATA_FIELDS as $field) {
            $result->{$field} = $data[$field] ?? null;
        }

        $result->MainOperation = (string) $data['MainOperation'];
        $result->ParsedResult = (string) ($data['ParsedResult'] ?? 'Unknown');
        $result->Interrupted = (bool) ($data['Interrupted'] ?? false);
        $result->Version = (string) ($data['Version'] ?? '');
        $result->EndTime = $data['EndTime'] ?? null;
        $result->BeginTime = $data['BeginTime'] ?? null;
        $result->Duration = (string) ($data['Duration'] ?? '00:00:00');
        $result->MessagesActualLength = (int) ($data['MessagesActualLength'] ?? 0);
        $result->WarningsActualLength = (int) ($data['WarningsActualLength'] ?? 0);
        $result->ErrorsActualLength = (int) ($data['ErrorsActualLength'] ?? 0);
        $result->BackendStatistics = is_array($data['BackendStatistics'] ?? null) ? $data['BackendStatistics'] : [];

        return $result;
    }

    /** List-type operations are read-only lookups and are not stored. */
    public function isIgnoredOperation(?string $operation): bool
    {
        return $operation === null || $operation === '' || str_starts_with($operation, 'List');
    }
}
