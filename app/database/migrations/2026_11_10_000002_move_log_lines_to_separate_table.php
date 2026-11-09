<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log lines are by far the largest part of a result row. Keeping them in the
 * main table made every list/statistics query read all of them, so they move
 * to their own table and are only loaded on the detail page.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional: a failed earlier run can leave this (empty) table behind.
        Schema::dropIfExists('backup_result_logs');

        Schema::create('backup_result_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('backup_result_id')->primary();
            $table->longText('log_lines');
            $table->foreign('backup_result_id')->references('id')->on('backup_results')->cascadeOnDelete();
        });

        // Query builder quotes identifiers per driver.
        DB::table('backup_result_logs')->insertUsing(
            ['backup_result_id', 'log_lines'],
            DB::table('backup_results')->select('id', 'log_lines')->whereNotNull('log_lines'),
        );

        Schema::table('backup_results', function (Blueprint $table) {
            $table->dropColumn('log_lines');
            $table->index('EndTime', 'backup_results_endtime_index');
        });
    }

    public function down(): void
    {
        Schema::table('backup_results', function (Blueprint $table) {
            $table->dropIndex('backup_results_endtime_index');
            $table->json('log_lines')->nullable();
        });

        DB::table('backup_result_logs')->orderBy('backup_result_id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('backup_results')->where('id', $row->backup_result_id)->update(['log_lines' => $row->log_lines]);
            }
        });

        Schema::dropIfExists('backup_result_logs');
    }
};
