<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * service_backup_runs is the history log for the per-service
     * "Generar copia descargable" feature exposed in the service
     * configuration UI as the "Backup y descarga" tab.
     *
     * Each row represents ONE generation attempt of ONE service
     * backup zip. The zip itself lives on the Coolify host under
     * /data/coolify/backups/services/<service-uuid>/, the path is
     * stored in artifact_path so the download controller and the
     * 30-minute prune action can find it again.
     *
     * Why a separate table from team_backup_runs:
     *  - Different scope: per-service vs per-team.
     *  - Different lifecycle: 30 min TTL with auto-expire vs the
     *    retention-N policy of team backups.
     *  - Different audience: clients can trigger and download
     *    these (scoped to projects they own), team backups are
     *    operator-only.
     *  - Different artifact format: zip vs tarball, intentional —
     *    clients are typically Windows users, zip avoids the "I
     *    can't open .tar.gz" support burden.
     *
     * status flow:
     *   pending → running → completed | failed
     *   completed → expired (after 30 min when prune runs)
     *
     * The expires_at column is what the prune action reads. We
     * could derive it from finished_at + 30 min on the fly, but
     * having it explicit means changing the TTL is a code-only
     * change going forward (no historical recompute) and the UI
     * can show "expira en 23 min" without time-zone gymnastics.
     */
    public function up(): void
    {
        Schema::create('service_backup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // user_id is the operator/client that triggered the
            // generation. We keep it for audit ("who downloaded the
            // production WP last week") and for the UI to show
            // "generado por X" on each row of the history list.
            // Nullable so a row from a deleted user account doesn't
            // become an FK violation on cascade.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('pending'); // pending|running|completed|failed|expired
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('artifact_path', 512)->nullable();
            $table->string('archive_name', 255)->nullable();
            $table->text('last_message')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'status']);
            $table->index(['team_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_backup_runs');
    }
};
