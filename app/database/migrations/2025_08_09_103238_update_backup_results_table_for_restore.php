<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('backup_results', function (Blueprint $table) {
            $table->json('TestResults')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('backup_results', function (Blueprint $table) {
            $table->json('TestResults')->nullable(false)->change();
        });
    }
};
