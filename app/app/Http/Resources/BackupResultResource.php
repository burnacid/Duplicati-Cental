<?php

namespace App\Http\Resources;

use App\Models\BackupResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BackupResult
 */
class BackupResultResource extends JsonResource
{
    /** Include log lines and all backend statistics (single result only). */
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $stats = (array) $this->BackendStatistics;
        $seconds = $this->BeginTime && $this->EndTime ? max(0, $this->EndTime->getTimestamp() - $this->BeginTime->getTimestamp()) : null;

        return [
            'id' => $this->id,
            'server_id' => $this->backup_server_id,
            'server_name' => $this->whenLoaded('backupServer', fn () => $this->backupServer->name),
            'backup_id' => $this->backup_id,
            'backup_name' => $this->backup_name,
            'operation' => $this->MainOperation,
            'status' => $this->status->value,
            'is_failure' => $this->status->isFailure(),
            'interrupted' => (bool) $this->Interrupted,
            'begin_time' => $this->BeginTime?->toIso8601String(),
            'end_time' => $this->EndTime?->toIso8601String(),
            'duration_seconds' => $seconds,
            'duplicati_version' => $this->Version,
            'source' => $this->source,
            'warnings' => (int) $this->WarningsActualLength,
            'errors' => (int) $this->ErrorsActualLength,
            'files' => [
                'examined' => $this->ExaminedFiles,
                'added' => $this->AddedFiles,
                'modified' => $this->ModifiedFiles,
                'deleted' => $this->DeletedFiles,
                'with_error' => $this->FilesWithError,
            ],
            'sizes' => [
                'source_bytes' => $this->SizeOfExaminedFiles,
                'added_bytes' => $this->SizeOfAddedFiles,
                'modified_bytes' => $this->SizeOfModifiedFiles,
                'backup_bytes' => $stats['KnownFileSize'] ?? null,
                'destination_free_bytes' => $stats['FreeQuotaSpace'] ?? null,
                'destination_total_bytes' => $stats['TotalQuotaSpace'] ?? null,
            ],
            'versions' => $stats['BackupListCount'] ?? null,
            'backend_statistics' => $this->when($this->detailed, $stats),
            'log_lines' => $this->when($this->detailed, fn () => $this->log_lines),
        ];
    }
}
