<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a user's event log (Event log page). Written through App\Services\EventLogger.
 */
class EventLog extends Model
{
    public const UPDATED_AT = null;

    public const CATEGORY_REPORT = 'report';

    public const CATEGORY_NOTIFICATION = 'notification';

    public const CATEGORY_HEARTBEAT = 'heartbeat';

    public const CATEGORY_REMOTE = 'remote';

    public const CATEGORIES = [
        self::CATEGORY_REPORT => 'Incoming reports',
        self::CATEGORY_NOTIFICATION => 'Notifications',
        self::CATEGORY_HEARTBEAT => 'Heartbeat pings',
        self::CATEGORY_REMOTE => 'Remote control',
    ];

    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_ERROR = 'error';

    public const LEVELS = [self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR];

    protected $fillable = ['user_id', 'backup_server_id', 'category', 'level', 'message', 'context'];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function scopeForUser(Builder $query, User|string $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function backupServer(): BelongsTo
    {
        return $this->belongsTo(BackupServer::class);
    }

    /** Flux badge colour for the level. */
    public function levelColor(): string
    {
        return match ($this->level) {
            self::LEVEL_ERROR => 'red',
            self::LEVEL_WARNING => 'amber',
            default => 'zinc',
        };
    }
}
