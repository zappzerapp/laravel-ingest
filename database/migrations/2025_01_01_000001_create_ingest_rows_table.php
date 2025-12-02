<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ingest_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingest_run_id')->constrained('ingest_runs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status');
            $table->json('data')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['ingest_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_rows');
    }
};