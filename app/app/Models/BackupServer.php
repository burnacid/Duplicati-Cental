<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class BackupServer extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'description', 'user_id', 'muted_until'];

    protected $hidden = ['api_key'];

    protected $casts = [
        'muted_until' => 'datetime',
    ];

    /** "Snooze until I resume" is stored as a date this far ahead. */
    public const MUTE_INDEFINITELY_YEARS = 100;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->api_key = Str::random(64);
        });
    }

    public function scopeOwnedBy(Builder $query, User|string|null $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function backupResults()
    {
        return $this->hasMany(BackupResult::class);
    }

    public function jobSettings()
    {
        return $this->hasMany(BackupJobSetting::class);
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    /** Whether alerts for this server, or for the given job on it, are snoozed right now. */
    public function isJobMuted(?string $backupId): bool
    {
        if ($this->isMuted()) {
            return true;
        }

        return $backupId !== null && $this->jobSettings()
            ->where('backup_id', $backupId)
            ->where('muted_until', '>', now())
            ->exists();
    }

    public function getBackupServerUrlAttribute()
    {
        return URL::to('/').'/api/backup-servers/'.$this->id.'/'.$this->api_key.'/backup-result';
    }
}
