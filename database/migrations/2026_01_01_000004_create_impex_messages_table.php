<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impex_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->nullable()
                ->constrained('impex_runs')->nullOnDelete();
            $table->ulid('step_id')->nullable()->index();
            $table->string('direction', 16);
            $table->string('channel', 191);
            $table->string('transport', 32);
            $table->text('endpoint');
            $table->string('method', 16)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('headers')->nullable();
            $table->foreignUlid('body_artifact_id')->nullable()
                ->constrained('impex_artifacts')->nullOnDelete();
            $table->text('body_preview')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->boolean('signature_valid')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('error')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            // A plain index cannot dedupe concurrent webhook redelivery: both
            // requests pass the SELECT and both insert. Scoped to the channel
            // because two suppliers may legitimately reuse a key.
            $table->unique(['channel', 'idempotency_key'], 'impex_messages_unique');
            $table->index(['direction', 'occurred_at']);
            $table->index(['run_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impex_messages');
    }
};
