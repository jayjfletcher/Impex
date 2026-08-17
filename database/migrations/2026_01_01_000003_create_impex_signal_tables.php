<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impex_signals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('impex_runs')->cascadeOnDelete();
            $table->string('name', 191);
            $table->json('payload')->nullable();
            $table->foreignUlid('payload_artifact_id')->nullable()
                ->constrained('impex_artifacts')->nullOnDelete();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestamp('delivered_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedInteger('consumed_sequence')->nullable();
            $table->timestamps();

            // Without this a redelivered POST inserts a duplicate that gets
            // consumed a second time the next time the run waits on the name.
            $table->unique(['run_id', 'name', 'idempotency_key'], 'impex_signals_unique');
            $table->index(['run_id', 'name', 'consumed_at']);
        });

        // SQS caps message delay at 15 minutes, so a workflow that waits a day
        // for a signal cannot be expressed as a delayed job. Long waits become
        // rows here, swept once a minute by `impex:tick`.
        Schema::create('impex_timers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('impex_runs')->cascadeOnDelete();
            $table->string('phase', 16)->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->string('kind', 32);
            $table->timestamp('wake_at')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->ulid('claim_token')->nullable();
            $table->timestamp('fired_at')->nullable();
            $table->timestamps();

            $table->index(['fired_at', 'wake_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impex_timers');
        Schema::dropIfExists('impex_signals');
    }
};
