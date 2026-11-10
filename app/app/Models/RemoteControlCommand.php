<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemoteControlCommand extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_TIMEOUT = 'timeout';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'remote_control_registration_id',
        'requested_by_user_id',
        'method',
        'path',
        'request_body',
        'request_headers',
        'status',
        'message_id',
        'response_status_code',
        'response_body',
        'response_headers',
        'error_message',
        'sent_at',
        'completed_at',
        'timeout_seconds',
    ];

    /** Seconds to wait for an answer when no timeout is set. */
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'request_headers' => 'array',
            'response_headers' => 'array',
            'response_status_code' => 'integer',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(RemoteControlRegistration::class, 'remote_control_registration_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_TIMEOUT], true);
    }
}
