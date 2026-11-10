<?php

namespace App\Livewire\RemoteControl\Concerns;

use Flux\Flux;
use Livewire\Attributes\Locked;

/**
 * Restore from the machine page: pick a version, browse its files, choose where to restore.
 * Uses Duplicati's /api/v2/backup/list-filesets and list-folder, then POST /api/v1/backup/{id}/restore.
 */
trait BrowsesRestore
{
    /** Entries per folder page. */
    public const RESTORE_PAGE_SIZE = 500;

    /** Listing a backup can take a while (it may read the remote storage). */
    private const LISTING_TIMEOUT = 180;

    public ?string $restoreBackupId = null;

    public string $restoreStep = 'versions';

    /** @var list<array{version: int, time: string, files: ?int, size: ?int}>|null */
    #[Locked]
    public ?array $restoreVersions = null;

    #[Locked]
    public ?string $restoreTime = null;

    #[Locked]
    public ?string $restoreFolder = null;

    /** @var list<array{path: string, name: string, dir: bool, size: int, modified: ?string}> */
    #[Locked]
    public array $restoreEntries = [];

    #[Locked]
    public int $restoreTotal = 0;

    /** @var list<string> selected paths */
    public array $restoreSelected = [];

    public string $restoreTarget = 'original';

    public string $restorePath = '';

    public bool $restoreOverwrite = false;

    public bool $restorePermissions = false;

    public ?string $restoreError = null;

    public function openRestore(string $backupId): void
    {
        $this->restoreBackupId = $backupId;
        $this->restoreStep = 'versions';
        $this->restoreVersions = null;
        $this->restoreTime = null;
        $this->restoreFolder = null;
        $this->restoreEntries = [];
        $this->restoreSelected = [];
        $this->restoreTarget = 'original';
        $this->restorePath = '';
        $this->restoreOverwrite = false;
        $this->restorePermissions = false;
        $this->restoreError = null;
        $this->resetValidation();

        $this->request('filesets', 'POST', '/api/v2/backup/list-filesets', ['BackupId' => $backupId], self::LISTING_TIMEOUT);
        Flux::modal('restore')->show();
    }

    public function chooseVersion(int $index): void
    {
        $version = $this->restoreVersions[$index] ?? null;
        if ($version === null) {
            return;
        }

        $this->restoreTime = $version['time'];
        $this->restoreSelected = [];
        $this->restoreStep = 'files';
        $this->browse(null);
    }

    /** Open a folder (null = the backup's source folders). */
    public function browse(?string $folder): void
    {
        if ($this->restoreBackupId === null || $this->restoreTime === null) {
            return;
        }

        $this->restoreFolder = $folder;
        $this->restoreEntries = [];
        $this->restoreError = null;
        $this->request('folder', 'POST', '/api/v2/backup/list-folder', [
            'BackupId' => $this->restoreBackupId,
            'Paths' => $folder === null ? null : [$folder],
            'Time' => $this->restoreTime,
            'PageSize' => self::RESTORE_PAGE_SIZE,
            'Page' => 0,
        ], self::LISTING_TIMEOUT);
    }

    public function browseUp(): void
    {
        $this->browse(self::parentFolder($this->restoreFolder));
    }

    public function toRestoreOptions(): void
    {
        if ($this->restoreSelected === []) {
            $this->restoreError = 'Select at least one file or folder.';

            return;
        }

        $this->restoreError = null;
        $this->restoreStep = 'options';
    }

    public function startRestore(): void
    {
        if ($this->restoreBackupId === null || $this->restoreTime === null || $this->restoreSelected === []) {
            return;
        }

        $this->validate([
            'restoreTarget' => ['required', 'in:original,custom'],
            'restorePath' => ['required_if:restoreTarget,custom', 'nullable', 'string', 'max:1000'],
            'restoreSelected' => ['required', 'array', 'max:1000'],
            'restoreSelected.*' => ['string', 'max:4000'],
        ], [], ['restorePath' => 'folder']);

        $started = $this->startOperation('restore', $this->restoreBackupId, [
            'paths' => array_map([self::class, 'restoreFilter'], array_values(array_unique($this->restoreSelected))),
            'time' => $this->restoreTime,
            'restore_path' => $this->restoreTarget === 'custom' ? trim($this->restorePath) : null,
            'overwrite' => $this->restoreOverwrite,
            'permissions' => $this->restorePermissions,
        ]);

        if ($started) {
            Flux::modal('restore')->close();
        } else {
            $this->restoreError = 'Another operation is still running for this job.';
        }
    }

    protected function onRestoreResponse(string $key, array $body, ?string $error): void
    {
        if ($error !== null) {
            $this->restoreError = $error;

            return;
        }

        $items = (array) ($body['Data'] ?? []);

        if ($key === 'filesets') {
            $this->restoreVersions = array_values(array_map(fn ($item) => [
                'version' => (int) ($item['Version'] ?? 0),
                'time' => (string) ($item['Time'] ?? ''),
                'files' => isset($item['FileCount']) ? (int) $item['FileCount'] : null,
                'size' => isset($item['FileSizes']) ? (int) $item['FileSizes'] : null,
            ], array_filter($items, fn ($item) => is_array($item) && filled($item['Time'] ?? null))));

            return;
        }

        // 'folder'
        $this->restoreTotal = (int) data_get($body, 'PageInfo.Total', count($items));
        $this->restoreEntries = array_values(array_map(function ($item) {
            $path = (string) ($item['Path'] ?? '');

            return [
                'path' => $path,
                'name' => self::baseName($path),
                'dir' => (bool) ($item['IsDirectory'] ?? false),
                'size' => (int) ($item['Size'] ?? 0),
                'modified' => $item['LastModified'] ?? null,
            ];
        }, array_filter($items, fn ($item) => is_array($item) && filled($item['Path'] ?? null))));
    }

    /**
     * Duplicati filter for a selected path, as Duplicati's own restore page builds it:
     * a folder (ending in a separator) becomes a regex matching everything below it,
     * a file a literal match.
     */
    public static function restoreFilter(string $path): string
    {
        if (str_ends_with($path, '/') || str_ends_with($path, '\\')) {
            return '['.preg_replace('/[.*+?^${}()|\[\]\\\\]/', '\\\\$0', $path).'.*]';
        }

        return '@'.$path;
    }

    public static function parentFolder(?string $folder): ?string
    {
        $trimmed = rtrim((string) $folder, '/\\');
        $position = max((int) strrpos($trimmed, '/'), (int) strrpos($trimmed, '\\'));

        // "/home/" → "/", "C:\Users\" → "C:\", "/" or "C:\" → back to the list of sources.
        return $trimmed === '' || ($position === 0 && ! str_starts_with($trimmed, '/') && ! str_starts_with($trimmed, '\\'))
            ? null
            : substr($trimmed, 0, $position + 1);
    }

    public static function baseName(string $path): string
    {
        $trimmed = rtrim($path, '/\\');
        $position = max(strrpos($trimmed, '/') ?: -1, strrpos($trimmed, '\\') ?: -1);

        $name = $position < 0 ? $trimmed : substr($trimmed, $position + 1);

        return $name === '' ? $path : $name;
    }
}
