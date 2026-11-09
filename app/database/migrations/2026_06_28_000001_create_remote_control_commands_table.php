<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('remote_control_commands')) {
            return;
        }

        Schema::create('remote_control_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('remote_control_registration_id');
            $table->foreign('remote_control_registration_id', 'rc_commands_registration_fk')
                ->references('id')
                ->on('remote_control_registrations')
                ->cascadeOnDelete();
            $table->uuid('requested_by_user_id')->nullable();
            $table->foreign('requested_by_user_id', 'rc_commands_requested_by_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->string('method');
            $table->string('path');
            $table->text('request_body')->nullable();
            $table->json('request_headers')->nullable();
            $table->string('status')->default('pending');
            $table->string('message_id')->nullable();
            $table->unsignedSmallInteger('response_status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'remote_control_registration_id'], 'rc_commands_status_registration_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_control_commands');
    }
};
