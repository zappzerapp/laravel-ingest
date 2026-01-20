<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelIngest\Enums\IngestStatus;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('ingest_runs')->nullOnDelete();
            $table->string('importer')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default(IngestStatus::PENDING->value)->index();
            $table->string('batch_id')->nullable()->index();

            $table->string('original_filename')->nullable();
            $table->string('processed_filepath')->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            $table->json('summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_runs');
    }
};
