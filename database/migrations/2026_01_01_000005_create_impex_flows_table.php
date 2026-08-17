<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The registry is the source of truth for which flows exist — code is.
        // This table holds only runtime overrides, so a flow can be disabled or
        // rescheduled from the dashboard without a deploy. A row here for an
        // unregistered slug is ignored.
        Schema::create('impex_flows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug')->unique();
            $table->boolean('enabled')->nullable();
            $table->string('schedule')->nullable();
            $table->string('queue')->nullable();
            $table->string('queue_connection')->nullable();
            $table->json('defaults')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impex_flows');
    }
};
