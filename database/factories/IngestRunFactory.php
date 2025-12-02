<?php

namespace LaravelIngest\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LaravelIngest\Enums\IngestStatus;
use LaravelIngest\Models\IngestRun;

class IngestRunFactory extends Factory
{
    protected $model = IngestRun::class;

    public function definition(): array
    {
        return [
            'importer_slug' => $this->faker->slug,
            'user_id' => null,
            'status' => IngestStatus::PENDING,
            'original_filename' => $this->faker->word . '.csv',
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
        ];
    }
}