<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A batch is ONE step in the replay history whatever its item count.
        // Per-item state lives below, in a table the replay never reads — which
        // is what keeps a million-item sweep to a two-step history.
        Schema::create('impex_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('impex_runs')->cascadeOnDelete();
            $table->ulid('step_id')->index();
            $table->string('source');
            $table->json('source_arguments')->nullable();
            $table->string('action');
            $table->unsignedInteger('chunk_size')->default(500);
            $table->float('allow_failures')->default(0);
            $table->unsignedInteger('max_attempts')->default(1);
            $table->boolean('seeded')->default(false);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('succeeded')->default(0);
            $table->unsignedBigInteger('failed')->default(0);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['seeded', 'finalized_at']);
        });

        Schema::create('impex_batch_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('batch_id')->constrained('impex_batches')->cascadeOnDelete();
            $table->string('item_key', 191);
            $table->json('payload')->nullable();
            $table->foreignUlid('payload_artifact_id')->nullable()
                ->constrained('impex_artifacts')->nullOnDelete();
            $table->string('status', 32);
            $table->json('result')->nullable();
            $table->foreignUlid('result_artifact_id')->nullable()
                ->constrained('impex_artifacts')->nullOnDelete();
            $table->json('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->ulid('lease_token')->nullable();
            $table->timestamp('leased_until')->nullable()->index();
            $table->timestamps();

            // The item-level equivalent of unique(run_id, sequence): a
            // redelivered seed loses the insert race and is a no-op.
            $table->unique(['batch_id', 'item_key'], 'impex_batch_items_unique');
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impex_batch_items');
        Schema::dropIfExists('impex_batches');
    }
};
