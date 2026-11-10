<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings per backup job (a job is a backup_server_id + backup_id pair in backup_results).
        Schema::create('backup_job_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('backup_server_id');
            $table->foreign('backup_server_id')->references('id')->on('backup_servers')->cascadeOnDelete();
            $table->string('backup_id');
            $table->unsignedInteger('expected_interval_hours')->nullable();
            $table->boolean('monitored')->default(true);
            $table->dateTime('muted_until')->nullable();
            $table->timestamps();

            $table->unique(['backup_server_id', 'backup_id']);
        });

        Schema::table('backup_servers', function (Blueprint $table) {
            $table->dateTime('muted_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_servers', function (Blueprint $table) {
            $table->dropColumn('muted_until');
        });

        Schema::dropIfExists('backup_job_settings');
    }
};
