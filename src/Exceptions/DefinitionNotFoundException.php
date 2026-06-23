<?php

declare(strict_types=1);

namespace LaravelIngest\Exceptions;

use Exception;

class DefinitionNotFoundException extends Exception
{
    public static function forSlug(string $slug): self
    {
        return new self(
            "No importer found with the slug '{$slug}'. " .
            "Please check your spelling or run 'php artisan ingest:list' to see available importers."
        );
    }
}
