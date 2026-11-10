<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RemoteControlRegistration extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'claimed_by_user_id',
        'backup_server_id',
        'instance_id',
        'machine_id',
        'machine_name',
        'install_id',
        'version',
        'operating_system',
        'registration_token',
        'claim_token',
        'status',
        'request_payload',
        'expires_at',
        'claimed_at',
        'jwt',
        'server_url',
        'certificate_url',
        'server_certificates',
        'local_encryption_key',
        'registration_base_url',
        'settings',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'server_certificates' => 'array',
        'settings' => 'array',
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function claimedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function backupServer(): BelongsTo
    {
        return $this->belongsTo(BackupServer::class, 'backup_server_id');
    }

    /**
     * Name shown in the UI: the (editable) name of the linked backup server, else the machine name.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->backupServer?->name ?: ($this->machine_name ?: 'Unknown machine');
    }

    public function agentState(): HasOne
    {
        return $this->hasOne(RemoteControlAgentState::class, 'remote_control_registration_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(RemoteControlCommand::class, 'remote_control_registration_id');
    }
}
