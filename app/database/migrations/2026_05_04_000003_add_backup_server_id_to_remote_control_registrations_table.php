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
        if (! Schema::hasTable('remote_control_registrations')) {
            return;
        }

        if (! Schema::hasColumn('remote_control_registrations', 'backup_server_id')) {
            Schema::table('remote_control_registrations', function (Blueprint $table) {
                $table->foreignUuid('backup_server_id')
                    ->nullable()
                    ->after('claimed_by_user_id')
                    ->constrained('backup_servers')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('remote_control_registrations') || ! Schema::hasColumn('remote_control_registrations', 'backup_server_id')) {
            return;
        }

        Schema::table('remote_control_registrations', function (Blueprint $table) {
            $table->dropForeign(['backup_server_id']);
            $table->dropColumn('backup_server_id');
        });
    }
};
