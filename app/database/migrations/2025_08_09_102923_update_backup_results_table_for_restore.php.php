<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('backup_results', function (Blueprint $table) {
            // Make existing fields nullable
            $table->integer('DeletedFiles')->nullable()->change();
            $table->integer('DeletedFolders')->nullable()->change();
            $table->integer('ModifiedFiles')->nullable()->change();
            $table->integer('ExaminedFiles')->nullable()->change();
            $table->integer('OpenedFiles')->nullable()->change();
            $table->integer('AddedFiles')->nullable()->change();
            $table->bigInteger('SizeOfModifiedFiles')->nullable()->change();
            $table->bigInteger('SizeOfAddedFiles')->nullable()->change();
            $table->bigInteger('SizeOfExaminedFiles')->nullable()->change();
            $table->bigInteger('SizeOfOpenedFiles')->nullable()->change();
            $table->integer('NotProcessedFiles')->nullable()->change();
            $table->integer('AddedFolders')->nullable()->change();
            $table->integer('TooLargeFiles')->nullable()->change();
            $table->integer('FilesWithError')->nullable()->change();
            $table->integer('ModifiedFolders')->nullable()->change();
            $table->integer('ModifiedSymlinks')->nullable()->change();
            $table->integer('AddedSymlinks')->nullable()->change();
            $table->integer('DeletedSymlinks')->nullable()->change();
            $table->boolean('PartialBackup')->nullable()->change();
            $table->boolean('Dryrun')->nullable()->change();

            // Add new fields for restore operations
            $table->integer('RestoredFiles')->nullable();
            $table->bigInteger('SizeOfRestoredFiles')->nullable();
            $table->integer('RestoredFolders')->nullable();
            $table->integer('RestoredSymlinks')->nullable();
            $table->integer('PatchedFiles')->nullable();
        });
    }

    public function down()
    {
        Schema::table('backup_results', function (Blueprint $table) {
            // Revert the nullable changes (if needed)
            $table->integer('DeletedFiles')->nullable(false)->change();
            $table->integer('DeletedFolders')->nullable(false)->change();
            $table->integer('ModifiedFiles')->nullable(false)->change();
            $table->integer('ExaminedFiles')->nullable(false)->change();
            $table->integer('OpenedFiles')->nullable(false)->change();
            $table->integer('AddedFiles')->nullable(false)->change();
            $table->bigInteger('SizeOfModifiedFiles')->nullable(false)->change();
            $table->bigInteger('SizeOfAddedFiles')->nullable(false)->change();
            $table->bigInteger('SizeOfExaminedFiles')->nullable(false)->change();
            $table->bigInteger('SizeOfOpenedFiles')->nullable(false)->change();
            $table->integer('NotProcessedFiles')->nullable(false)->change();
            $table->integer('AddedFolders')->nullable(false)->change();
            $table->integer('TooLargeFiles')->nullable(false)->change();
            $table->integer('FilesWithError')->nullable(false)->change();
            $table->integer('ModifiedFolders')->nullable(false)->change();
            $table->integer('ModifiedSymlinks')->nullable(false)->change();
            $table->integer('AddedSymlinks')->nullable(false)->change();
            $table->integer('DeletedSymlinks')->nullable(false)->change();
            $table->boolean('PartialBackup')->nullable(false)->change();
            $table->boolean('Dryrun')->nullable(false)->change();

            // Remove the new fields
            $table->dropColumn([
                'RestoredFiles',
                'SizeOfRestoredFiles',
                'RestoredFolders',
                'RestoredSymlinks',
                'PatchedFiles',
            ]);
        });
    }
};
