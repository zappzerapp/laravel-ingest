<?php

declare(strict_types=1);

namespace LaravelIngest\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class IngestExceptionHandler
{
    public static function register(ExceptionHandler $handler): void
    {
        $handler->renderable(fn(NoFailedRowsException $e) => response()->json([
            'message' => $e->getMessage(),
        ], $e->getStatusCode()));

        $handler->renderable(fn(DefinitionNotFoundException $e) => response()->json([
            'message' => $e->getMessage() ?: 'Importer definition not found.',
        ], 404));

        $handler->renderable(fn(InvalidConfigurationException $e) => response()->json([
            'message' => $e->getMessage() ?: 'Invalid ingest configuration.',
        ], 422));

        $handler->renderable(fn(ConcurrencyException $e) => response()->json([
            'message' => $e->getMessage(),
        ], 409));
    }
}
