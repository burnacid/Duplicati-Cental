<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBackupResultsTable extends Migration
{
    public function up()
    {
        Schema::create('backup_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('backup_server_id')->constrained()->onDelete('cascade');
            $table->integer('DeletedFiles');
            $table->integer('DeletedFolders');
            $table->integer('ModifiedFiles');
            $table->integer('ExaminedFiles');
            $table->integer('OpenedFiles');
            $table->integer('AddedFiles');
            $table->bigInteger('SizeOfModifiedFiles');
            $table->bigInteger('SizeOfAddedFiles');
            $table->bigInteger('SizeOfExaminedFiles');
            $table->bigInteger('SizeOfOpenedFiles');
            $table->integer('NotProcessedFiles');
            $table->integer('AddedFolders');
            $table->integer('TooLargeFiles');
            $table->integer('FilesWithError');
            $table->integer('ModifiedFolders');
            $table->integer('ModifiedSymlinks');
            $table->integer('AddedSymlinks');
            $table->integer('DeletedSymlinks');
            $table->boolean('PartialBackup');
            $table->boolean('Dryrun');
            $table->string('MainOperation');
            $table->json('CompactResults')->nullable();
            $table->json('VacuumResults')->nullable();
            $table->json('DeleteResults')->nullable();
            $table->json('RepairResults')->nullable();
            $table->json('TestResults');
            $table->string('ParsedResult');
            $table->boolean('Interrupted');
            $table->string('Version');
            $table->dateTime('EndTime');
            $table->dateTime('BeginTime');
            $table->string('Duration');
            $table->integer('MessagesActualLength');
            $table->integer('WarningsActualLength');
            $table->integer('ErrorsActualLength');
            $table->json('BackendStatistics');
            $table->json('extra');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('backup_results');
    }
}
