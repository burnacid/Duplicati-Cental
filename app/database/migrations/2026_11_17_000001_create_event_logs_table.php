<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What happened per user: incoming reports (stored, ignored, rejected), notifications and heartbeat pings.
        Schema::create('event_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->uuid('backup_server_id')->nullable();
            $table->foreign('backup_server_id')->references('id')->on('backup_servers')->nullOnDelete();
            $table->string('category', 32);
            $table->string('level', 16);
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_logs');
    }
};
