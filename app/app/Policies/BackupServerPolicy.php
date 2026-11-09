<?php

namespace App\Policies;

use App\Models\BackupServer;
use App\Models\User;

class BackupServerPolicy
{
    public function view(User $user, BackupServer $backupServer): bool
    {
        return $user->id === $backupServer->user_id;
    }

    public function update(User $user, BackupServer $backupServer): bool
    {
        return $user->id === $backupServer->user_id;
    }

    public function delete(User $user, BackupServer $backupServer): bool
    {
        return $user->id === $backupServer->user_id;
    }
}
