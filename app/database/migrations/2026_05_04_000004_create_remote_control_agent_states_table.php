<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('remote_control_agent_states')) {
            return;
        }

        Schema::create('remote_control_agent_states', function (Blueprint $table) {
            $table->id();
            $table->uuid('remote_control_registration_id');
            $table->unique('remote_control_registration_id', 'rc_agent_states_registration_unique');
            $table->foreign('remote_control_registration_id', 'rc_agent_states_registration_fk')
                ->references('id')
                ->on('remote_control_registrations')
                ->cascadeOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->unsignedInteger('last_applied_settings_version')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remote_control_agent_states');
    }
};

