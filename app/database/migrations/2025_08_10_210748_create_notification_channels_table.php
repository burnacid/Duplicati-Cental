<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->uuid();
            $table->foreignUuid('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('name');
            $table->string('type'); // email, discord, webhook, etc.
            $table->json('config'); // Store channel-specific configuration
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
