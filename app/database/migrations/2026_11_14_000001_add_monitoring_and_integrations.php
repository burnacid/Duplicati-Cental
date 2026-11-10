<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Users have UUID keys; the original table had an integer tokenable_id. No tokens could be
        // created before (there was no UI), so the table is rebuilt; existing rows are copied anyway.
        $tokens = Schema::hasTable('personal_access_tokens') ? DB::table('personal_access_tokens')->get() : collect();
        Schema::dropIfExists('personal_access_tokens');
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        foreach ($tokens as $token) {
            DB::table('personal_access_tokens')->insert((array) $token);
        }

        Schema::table('backup_job_settings', function (Blueprint $table) {
            // Alert rules (null = off).
            $table->unsignedInteger('alert_size_change_percent')->nullable();
            $table->decimal('alert_duration_factor', 5, 1)->nullable();
            $table->unsignedInteger('alert_unchanged_runs')->nullable();
            $table->unsignedInteger('alert_quota_free_percent')->nullable();
            // Pinged after each successful backup (encrypted: push URLs contain a secret).
            $table->text('heartbeat_url')->nullable();
            // Public status page.
            $table->boolean('on_status_page')->default(false);
            $table->string('status_page_name')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('status_page_token', 64)->nullable()->unique();
            $table->string('status_page_title')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['status_page_token']);
            $table->dropColumn(['status_page_token', 'status_page_title']);
        });

        Schema::table('backup_job_settings', function (Blueprint $table) {
            $table->dropColumn([
                'alert_size_change_percent', 'alert_duration_factor', 'alert_unchanged_runs', 'alert_quota_free_percent',
                'heartbeat_url', 'on_status_page', 'status_page_name',
            ]);
        });
    }
};
