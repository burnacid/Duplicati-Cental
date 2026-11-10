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

        if (! Schema::hasColumn('remote_control_registrations', 'claimed_by_user_id')) {
            Schema::table('remote_control_registrations', function (Blueprint $table) {
                $table->foreignUuid('claimed_by_user_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('remote_control_registrations') || ! Schema::hasColumn('remote_control_registrations', 'claimed_by_user_id')) {
            return;
        }

        Schema::table('remote_control_registrations', function (Blueprint $table) {
            $table->dropForeign(['claimed_by_user_id']);
            $table->dropColumn('claimed_by_user_id');
        });
    }
};
