<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * team_backup_settings holds the "Backups" feature config per team.
     *
     * Design: one row per team. Two destinations (local + sftp) share
     * the same schedule so they chain: local runs first at its cron,
     * sftp runs immediately after local completes. Keeping both in a
     * single row makes the "enable/disable", "save schedule" and
     * "edit emails" screens a single form with no joins.
     *
     * All sensitive fields (password, private key, passphrase) are
     * encrypted at rest via the Eloquent `encrypted` cast on the
     * model, so nothing here uses a different column type — the
     * encrypted blob lands in a plain TEXT and Laravel handles the
     * AES round trip with APP_KEY.
     */
    public function up(): void
    {
        Schema::create('team_backup_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();

            // Local destination ----------------------------------------
            $table->boolean('local_enabled')->default(true);
            $table->string('local_cron', 64)->default('0 22 * * 5');
            $table->unsignedSmallInteger('local_retention')->default(4);
            $table->string('local_scope', 16)->default('full'); // full|databases|files
            $table->string('local_file_mode', 16)->default('persistent'); // persistent|full-container
            $table->string('local_path', 255)->default('/data/coolify/backups/custom');

            // SFTP destination -----------------------------------------
            $table->boolean('sftp_enabled')->default(false);
            $table->unsignedSmallInteger('sftp_retention')->default(8);
            $table->string('sftp_host', 255)->nullable();
            $table->unsignedSmallInteger('sftp_port')->default(22);
            $table->string('sftp_username', 120)->nullable();
            $table->string('sftp_remote_path', 255)->default('/backups/coolify');
            $table->string('sftp_auth_method', 16)->default('password'); // password|key
            $table->text('sftp_password')->nullable();
            $table->text('sftp_private_key')->nullable();
            $table->text('sftp_private_key_passphrase')->nullable();

            // Notifications --------------------------------------------
            $table->boolean('notify_on_failure')->default(true);
            $table->boolean('notify_on_success')->default(false);
            // JSON array of extra recipient emails. Defaults are seeded
            // by the model factory / mount() of the settings component.
            $table->json('notification_emails')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_backup_settings');
    }
};
