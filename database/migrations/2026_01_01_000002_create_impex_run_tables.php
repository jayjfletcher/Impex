<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impex_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('flow')->index();
            $table->string('flow_class');
            $table->string('flow_version', 64)->nullable();
            $table->string('status', 32);
            $table->string('trigger', 32);
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->json('input')->nullable();
            $table->foreignUlid('input_artifact_id')->nullable()
                ->constrained('impex_artifacts')->nullOnDelete();
            $table->json('result')->nullable();
            $table->foreignUlid('result_artifact_id')->nullable()
                ->constrained('impex_artifacts')->nullOnDelete();
            $table->json('error')->nullable();
            $table->json('tags')->nullable();
            $table->ulid('parent_run_id')->nullable()->index();
            $table->unsignedInteger('parent_sequence')->nullable();
            // What happens to this child if its parent finishes first.
            $table->string('close_policy', 16)->nullable();
            $table->string('queue_connection')->nullable();
            $table->string('queue')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['flow', 'status']);
        });

        Schema::create('impex_run_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('impex_runs')->cascadeOnDelete();
            $table->string('phase', 16);
            $table->unsignedInteger('sequence');
            $table->string('type', 32);
            $table->string('name');
            $table->string('status', 32);
            $table->json('input')->nullable();
            $table->foreignUlid('input_artifact_id')->nullable()
                ->constrained('impex_artifacts')->nullOnDelete();
            $table->json('result')->nullable();
            $table->foreignUlid('result_artifact_id')->nullable()
                ->constrained('impex_artifacts')->nullOnDelete();
            $table->json('error')->nullable();
            // The rollback is captured when the forward step is recorded,
            // so rollback never needs to replay the flow to discover it.
            $table->json('rollback')->nullable();
            $table->boolean('undone')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(1);
            // A step that cannot finish inside one invocation yields a cursor
            // and is re-dispatched from it, so work longer than the Lambda
            // ceiling survives as a series of bounded invocations.
            $table->json('cursor')->nullable();
            $table->unsignedInteger('resumptions')->default(0);
            // Lease-before-execute: a step is claimed before its side effects
            // run, and a lapsed lease is reclaimable so a timed-out invocation
            // cannot wedge the run.
            $table->ulid('lease_token')->nullable();
            $table->timestamp('leased_until')->nullable()->index();
            $table->unsignedInteger('undoes_sequence')->nullable();
            // Groups steps declared inside one unit() block, so a rollback can
            // find a step's peers and apply the group's policy to them.
            $table->string('unit_id', 26)->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'phase', 'sequence']);
            $table->index(['run_id', 'status']);
        });

        Schema::create('impex_run_owners', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('impex_runs')->cascadeOnDelete();
            $table->string('owner_type', 191);
            $table->string('owner_id', 64);
            $table->string('role', 32);
            $table->timestamps();

            // 26 + 191 + 64 + 32 = 313 chars, 1252 bytes under utf8mb4 —
            // inside InnoDB's 3072-byte key limit.
            $table->unique(['run_id', 'owner_type', 'owner_id', 'role'], 'impex_run_owners_unique');
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impex_run_owners');
        Schema::dropIfExists('impex_run_steps');
        Schema::dropIfExists('impex_runs');
    }
};
