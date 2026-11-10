<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_control_commands', function (Blueprint $table) {
            // Seconds to wait for an answer (null = the default 30). Listing a backup can take longer.
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
        });

        Schema::table('remote_control_agent_states', function (Blueprint $table) {
            // Set when an "instance offline" alert was sent; cleared when it is back.
            $table->timestamp('offline_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('remote_control_agent_states', function (Blueprint $table) {
            $table->dropColumn('offline_notified_at');
        });

        Schema::table('remote_control_commands', function (Blueprint $table) {
            $table->dropColumn('timeout_seconds');
        });
    }
};
