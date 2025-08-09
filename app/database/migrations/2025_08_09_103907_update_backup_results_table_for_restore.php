<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('backup_results', function (Blueprint $table) {
            $table->json('log_lines')->nullable();
        });
    }

    public function down()
    {
        Schema::table('backup_results', function (Blueprint $table) {
            $table->dropColumn('log_lines');
        });
    }
};
