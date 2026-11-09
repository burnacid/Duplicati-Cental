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
        Schema::create('remote_control_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('backup_server_id')->nullable()->constrained('backup_servers')->nullOnDelete();
            $table->string('instance_id')->nullable()->index();
            $table->string('machine_id')->nullable()->index();
            $table->string('machine_name')->nullable();
            $table->string('install_id')->nullable();
            $table->string('version')->nullable();
            $table->string('operating_system')->nullable();

            $table->string('registration_token', 80)->unique();
            $table->string('claim_token', 80)->unique();
            $table->string('status', 24)->default('pending')->index();

            $table->json('request_payload')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('claimed_at')->nullable();

            $table->text('jwt')->nullable();
            $table->string('server_url')->nullable();
            $table->string('certificate_url')->nullable();
            $table->json('server_certificates')->nullable();
            $table->text('local_encryption_key')->nullable();
            $table->json('settings')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remote_control_registrations');
    }
};

