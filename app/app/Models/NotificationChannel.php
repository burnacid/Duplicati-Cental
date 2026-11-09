<?php

namespace App\Models;

use App\Enums\BackupStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

class NotificationChannel extends Model
{
    use HasFactory;

    public const NOTIFY_ALL = 'all';
    public const NOTIFY_WARNING = 'warning';
    public const NOTIFY_ERROR = 'error';

    protected $fillable = ['name', 'type', 'config', 'is_active', 'notify_on', 'notify_overdue', 'user_id'];

    protected $hidden = ['config'];

    protected $casts = [
        'config' => 'encrypted:array',
        'is_active' => 'boolean',
        'notify_overdue' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether a result with this status should be sent to this channel.
     */
    public function wantsStatus(BackupStatus $status): bool
    {
        return match ($this->notify_on) {
            self::NOTIFY_ALL => true,
            self::NOTIFY_ERROR => $status->isFailure(),
            default => $status->severity() >= BackupStatus::Warning->severity(),
        };
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($channel) {
            $availableTypes = array_keys(Config::get('notification_channels.available_types'));
            if (! in_array($channel->type, $availableTypes, true)) {
                throw new \InvalidArgumentException("Invalid channel type: {$channel->type}");
            }
        });
    }
}
