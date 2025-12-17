<?php

declare(strict_types=1);

namespace LaravelIngest\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Models\IngestRow;
use LaravelIngest\Models\IngestRun;

class IngestRowFactory extends Factory
{
    protected $model = IngestRow::class;

    public function definition(): array
    {
        return [
            'ingest_run_id' => IngestRun::factory(),
            'row_number' => $this->faker->numberBetween(1, 1000),
            'status' => IngestStatus::PENDING->value,
            'data' => json_encode(['email' => $this->faker->email]),
            'errors' => null,
        ];
    }
}