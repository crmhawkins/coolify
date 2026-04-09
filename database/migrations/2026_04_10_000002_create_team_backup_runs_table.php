<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * team_backup_runs is the history log for the new "Backups" feature.
     *
     * Each row represents ONE destination of ONE backup attempt — so a
     * full chained run (local → sftp) writes two rows, linked to the
     * same `batch_uuid`. This lets the UI show local/sftp independently
     * (e.g. local OK, sftp FAILED) without cramming two statuses into
     * one row.
     *
     * Fields you can't derive from the settings row:
     * - started_at / finished_at: for duration + "average time" stats
     *   that the ETA estimate uses on subsequent runs.
     * - size_bytes: final tarball size on disk. Used for the
     *   "estimated size" box and for retention pruning.
     * - artifact_path: absolute path on the Coolify host (local) or
     *   full `sftp://host/path` URL (sftp) so we can delete it during
     *   retention without re-computing it.
     * - stats_json: free-form bag of numbers for the run summary —
     *   databases dumped, files archived, exit codes per sub-step.
     */
    public function up(): void
    {
        Schema::create('team_backup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_uuid')->index();
            $table->string('destination', 16); // local|sftp
            $table->string('scope', 16); // full|databases|files
            $table->string('trigger', 16); // manual|scheduled
            $table->string('status', 16)->default('pending'); // pending|running|completed|failed|skipped
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('artifact_path', 512)->nullable();
            $table->text('last_message')->nullable();
            $table->json('stats_json')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'destination', 'status']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_backup_runs');
    }
};
