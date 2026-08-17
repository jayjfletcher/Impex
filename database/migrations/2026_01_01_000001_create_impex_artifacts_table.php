<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Artifacts are always written before the row that points at them, so
        // this table is created first and carries no foreign keys of its own.
        // The run/step/message columns are plain indexed ULIDs: constraining
        // them would create a cycle with the *_artifact_id columns, which do
        // hold real constraints. Orphans in this direction are swept by
        // `impex:prune`.
        Schema::create('impex_artifacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('disk');
            $table->string('path', 1024);
            $table->string('kind', 32)->index();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('bytes');
            $table->string('checksum', 64)->nullable();
            $table->ulid('run_id')->nullable()->index();
            $table->ulid('step_id')->nullable()->index();
            $table->ulid('message_id')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impex_artifacts');
    }
};
