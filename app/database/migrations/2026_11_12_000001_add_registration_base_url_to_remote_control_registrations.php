<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_control_registrations', function (Blueprint $table) {
            // The address the Duplicati instance used to reach us when it registered
            // (e.g. https://central.example.com or http://10.0.0.5:8181). Used for the
            // websocket, certificates and reporting URLs it gets when claimed.
            $table->string('registration_base_url', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('remote_control_registrations', function (Blueprint $table) {
            $table->dropColumn('registration_base_url');
        });
    }
};
