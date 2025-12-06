<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ingest_runs', function (Blueprint $table) {
            $table->foreignId('retried_from_run_id')->nullable()->after('id')->constrained('ingest_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ingest_runs', function (Blueprint $table) {
            $table->dropForeign(['retried_from_run_id']);
            $table->dropColumn('retried_from_run_id');
        });
    }
};