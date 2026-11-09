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

    protected $fillable = ['name', 'description', 'user_id'];

    protected $hidden = ['api_key'];

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

    public function getBackupServerUrlAttribute()
    {
        return URL::to('/').'/api/backup-servers/'.$this->id.'/'.$this->api_key.'/backup-result';
    }
}
