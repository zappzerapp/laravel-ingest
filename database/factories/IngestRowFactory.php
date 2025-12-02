<?php

namespace LaravelIngest\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
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
            'status' => 'pending',
            'data' => json_encode(['email' => $this->faker->email]),
            'errors' => null,
        ];
    }
}