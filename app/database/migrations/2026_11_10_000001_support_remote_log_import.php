<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log pages can be far larger than 64 KB (MySQL TEXT).
        Schema::table('remote_control_commands', function (Blueprint $table) {
            $table->longText('request_body')->nullable()->change();
            $table->longText('response_body')->nullable()->change();
        });

        Schema::table('backup_results', function (Blueprint $table) {
            // 'report' = pushed by Duplicati, 'remote-log' = imported from the instance's local log.
            $table->string('source', 20)->default('report');
            $table->unsignedBigInteger('remote_log_id')->nullable();
            $table->index(['backup_server_id', 'backup_id', 'BeginTime'], 'backup_results_server_backup_begintime_index');
        });
    }

    public function down(): void
    {
        Schema::table('backup_results', function (Blueprint $table) {
            $table->dropIndex('backup_results_server_backup_begintime_index');
            $table->dropColumn(['source', 'remote_log_id']);
        });

        Schema::table('remote_control_commands', function (Blueprint $table) {
            $table->text('request_body')->nullable()->change();
            $table->text('response_body')->nullable()->change();
        });
    }
};
