<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class BackupServer extends Model
{
    use HasFactory, hasUuids;

    protected $fillable = ['name', 'description', 'user_id'];

    protected $keyType = 'string';
    public $incrementing = false;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->id = Str::uuid();
            $model->api_key = Str::random(64);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getBackupServerUrlAttribute()
    {
        return url::to('/').'/api/backup-servers/'.$this->id.'/'.$this->api_key.'/backup-result';
    }
}
