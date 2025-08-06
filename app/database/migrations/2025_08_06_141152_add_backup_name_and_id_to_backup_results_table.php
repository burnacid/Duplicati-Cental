<?php

use App\Models\BackupResult;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('backup_results', function (Blueprint $table) {
            $table->string('backup_name')->nullable()->after('backup_server_id');
            $table->string('backup_id')->nullable()->after('backup_name');
        });

        $results = BackupResult::whereNull('backup_name')->orWhereNull('backup_id')->get();

        foreach ($results as $result) {
            $extra = $result->extra;
            if (isset($extra['backup-name'])) {
                $result->backup_name = $extra['backup-name'];
            }
            if (isset($extra['backup-id'])) {
                $result->backup_id = $extra['backup-id'];
            }
            $result->save();
        }

    }

    public function down()
    {
        Schema::table('backup_results', function (Blueprint $table) {
            $table->dropColumn(['backup_name', 'backup_id']);
        });
    }
};
