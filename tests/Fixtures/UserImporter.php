<?php

namespace LaravelIngest\Tests\Fixtures;

use LaravelIngest\Contracts\IngestDefinition;
use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\Enums\SourceType;
use LaravelIngest\IngestConfig;
use LaravelIngest\Tests\Fixtures\Models\User;

class UserImporter implements IngestDefinition
{
    public function getConfig(): IngestConfig
    {
        return IngestConfig::for(User::class)
            ->fromSource(SourceType::UPLOAD)
            ->keyedBy('user_email')
            ->onDuplicate(DuplicateStrategy::UPDATE)
            ->map('full_name', 'name')
            ->map('user_email', 'email')
            ->mapAndTransform('is_admin', 'is_admin', fn($value) => strtolower($value) === 'yes' ? 1 : 0)
            ->validate([
                'full_name' => 'required|string',
                'user_email' => 'required|email',
            ]);
    }
}