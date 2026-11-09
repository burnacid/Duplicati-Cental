<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_results', function (Blueprint $table) {
            $table->index(['backup_server_id', 'EndTime'], 'backup_results_server_endtime_index');
            $table->index(['backup_server_id', 'backup_id', 'EndTime'], 'backup_results_server_backup_endtime_index');
        });
    }

    public function down(): void
    {
        Schema::table('backup_results', function (Blueprint $table) {
            $table->dropIndex('backup_results_server_endtime_index');
            $table->dropIndex('backup_results_server_backup_endtime_index');
        });
    }
};
