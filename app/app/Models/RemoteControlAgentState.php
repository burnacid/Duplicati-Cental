<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemoteControlAgentState extends Model
{
    protected $fillable = [
        'remote_control_registration_id',
        'connected_at',
        'last_seen_at',
        'disconnected_at',
        'last_applied_settings_version',
        'metadata',
        'offline_notified_at',
    ];

    protected $casts = [
        'offline_notified_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'last_applied_settings_version' => 'integer',
        'metadata' => 'array',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(RemoteControlRegistration::class, 'remote_control_registration_id');
    }

    public function getIsConnectedAttribute(): bool
    {
        if (! $this->last_seen_at) {
            return false;
        }

        return $this->last_seen_at->gt(now()->subSeconds(90));
    }
}
