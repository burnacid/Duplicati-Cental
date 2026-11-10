<?php

namespace App\Services;

use App\Models\BackupServer;
use App\Models\RemoteControlRegistration;
use App\Models\User;

class RemoteControlProvisioningService
{
    public function ensureBackupServer(RemoteControlRegistration $registration, User $user): BackupServer
    {
        if ($registration->backupServer && $registration->backupServer->user_id === $user->id) {
            return $registration->backupServer;
        }

        $name = $this->generateUniqueServerName($user, $registration->machine_name ?: 'Remote Instance');

        $backupServer = BackupServer::query()->create([
            'name' => $name,
            'description' => 'Auto-created from remote control claim'.($registration->machine_id ? (': '.$registration->machine_id) : ''),
            'user_id' => $user->id,
        ]);

        $registration->backup_server_id = $backupServer->id;
        $registration->save();

        return $backupServer;
    }

    private function generateUniqueServerName(User $user, string $baseName): string
    {
        $name = $baseName;
        $index = 2;

        while (BackupServer::query()->where('user_id', $user->id)->where('name', $name)->exists()) {
            $name = $baseName.' ('.$index.')';
            $index++;
        }

        return $name;
    }
}
