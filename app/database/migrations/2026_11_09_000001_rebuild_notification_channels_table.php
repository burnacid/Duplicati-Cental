<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The original table had no primary key (it used $table->uuid(), which only
 * adds a "uuid" column). Rebuild it with an auto-increment id, notification
 * rules, and encrypted config (it can hold SMTP passwords and webhook secrets).
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = Schema::hasTable('notification_channels')
            ? DB::table('notification_channels')->get()
            : collect();

        Schema::dropIfExists('notification_channels');

        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->text('config');
            $table->boolean('is_active')->default(true);
            $table->string('notify_on')->default('warning'); // all | warning | error
            $table->boolean('notify_overdue')->default(true);
            $table->timestamps();
        });

        foreach ($existing as $row) {
            DB::table('notification_channels')->insert([
                'user_id' => $row->user_id,
                'name' => $row->name,
                'type' => $row->type,
                'config' => Crypt::encryptString($row->config ?: '{}'),
                'is_active' => $row->is_active,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');

        Schema::create('notification_channels', function (Blueprint $table) {
            $table->uuid();
            $table->foreignUuid('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('name');
            $table->string('type');
            $table->json('config');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
};
