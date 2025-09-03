<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

class NotificationChannel extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'config', 'is_active', 'user_id'];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function boot()
    {
        parent::boot();

        static::saving(function ($channel) {
            $availableTypes = array_keys(Config::get('notification_channels.available_types'));
            if (!in_array($channel->type, $availableTypes)) {
                throw new \InvalidArgumentException("Invalid channel type: {$channel->type}");
            }
        });
    }
}
