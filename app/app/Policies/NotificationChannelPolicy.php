<?php

namespace App\Policies;

use App\Models\NotificationChannel;
use App\Models\User;

class NotificationChannelPolicy
{
    public function update(User $user, NotificationChannel $notificationChannel): bool
    {
        return $user->id === $notificationChannel->user_id;
    }

    public function delete(User $user, NotificationChannel $notificationChannel): bool
    {
        return $user->id === $notificationChannel->user_id;
    }
}
