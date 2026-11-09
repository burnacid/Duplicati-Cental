<?php

namespace App\Livewire\Concerns;

trait FormatsBackupValues
{
    protected function formatSize($bytes): string
    {
        $bytes = (float) ($bytes ?? 0);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Formats a .NET TimeSpan string such as "00:05:12.123" or "1.02:03:04.5".
     */
    protected function formatDuration($duration): string
    {
        if (! is_string($duration) || ! preg_match('/^(?:(\d+)\.)?(\d+):(\d+):(\d+)/', $duration, $m)) {
            return (string) $duration;
        }

        $parts = [
            'day' => (int) $m[1],
            'hour' => (int) $m[2],
            'minute' => (int) $m[3],
            'second' => (int) $m[4],
        ];

        $result = [];
        foreach ($parts as $unit => $value) {
            if ($value > 0) {
                $result[] = $value . ' ' . $unit . ($value !== 1 ? 's' : '');
            }
        }

        return $result === [] ? '0 seconds' : implode(', ', $result);
    }
}
