<?php

declare(strict_types=1);

use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\JsonResponse;
use LaravelIngest\Exceptions\ConcurrencyException;
use LaravelIngest\Exceptions\DefinitionNotFoundException;
use LaravelIngest\Exceptions\IngestExceptionHandler;
use LaravelIngest\Exceptions\InvalidConfigurationException;
use LaravelIngest\Exceptions\NoFailedRowsException;

it('returns correct response for NoFailedRowsException', function () {
    $exception = new NoFailedRowsException();

    $handler = Mockery::mock(Handler::class);
    $capturedResponse = null;

    $handler->shouldReceive('renderable')
        ->with(Mockery::type('callable'))
        ->andReturnUsing(function ($callback) use (&$capturedResponse, $exception) {
            if ($capturedResponse === null) {
                // Capture only the first call (NoFailedRowsException)
                $capturedResponse = $callback($exception);
            }

            return $this;
        })
        ->atLeast();

    IngestExceptionHandler::register($handler);

    expect($capturedResponse)
        ->toBeInstanceOf(JsonResponse::class)
        ->getStatusCode()->toBe(400)
        ->and($capturedResponse->getData(true))
        ->toBe(['message' => 'The original run has no failed rows to retry.']);
});

it('returns correct response for DefinitionNotFoundException with slug', function () {
    $exception = DefinitionNotFoundException::forSlug('test-importer');

    $handler = Mockery::mock(Handler::class);
    $capturedResponse = null;
    $callCount = 0;

    $handler->shouldReceive('renderable')
        ->with(Mockery::type('callable'))
        ->andReturnUsing(function ($callback) use (&$capturedResponse, &$callCount, $exception) {
            $callCount++;
            // Capture the second call (DefinitionNotFoundException)
            if ($callCount === 2) {
                $capturedResponse = $callback($exception);
            }

            return $this;
        })
        ->atLeast();

    IngestExceptionHandler::register($handler);

    expect($capturedResponse)
        ->toBeInstanceOf(JsonResponse::class)
        ->getStatusCode()->toBe(404)
        ->and($capturedResponse->getData(true))
        ->toBe(['message' => "No importer found with the slug 'test-importer'. Please check your spelling or run 'php artisan ingest:list' to see available importers."]);
});

it('returns correct response for DefinitionNotFoundException with custom message', function () {
    $exception = new DefinitionNotFoundException('Custom not found message');

    $handler = Mockery::mock(Handler::class);
    $capturedResponse = null;
    $callCount = 0;

    $handler->shouldReceive('renderable')
        ->with(Mockery::type('callable'))
        ->andReturnUsing(function ($callback) use (&$capturedResponse, &$callCount, $exception) {
            $callCount++;
            if ($callCount === 2) {
                $capturedResponse = $callback($exception);
            }

            return $this;
        })
        ->atLeast();

    IngestExceptionHandler::register($handler);

    expect($capturedResponse)
        ->getStatusCode()->toBe(404)
        ->and($capturedResponse->getData(true))
        ->toBe(['message' => 'Custom not found message']);
});

it('returns default message for empty DefinitionNotFoundException', function () {
    $exception = new DefinitionNotFoundException();

    $handler = Mockery::mock(Handler::class);
    $capturedResponse = null;
    $callCount = 0;

    $handler->shouldReceive('renderable')
        ->with(Mockery::type('callable'))
        ->andReturnUsing(function ($callback) use (&$capturedResponse, &$callCount, $exception) {
            $callCount++;
            if ($callCount === 2) {
                $capturedResponse = $callback($exception);
            }

            return $this;
        })
        ->atLeast();

    IngestExceptionHandler::register($handler);

    expect($capturedResponse)
        ->getStatusCode()->toBe(404)
        ->and($capturedResponse->getData(true))
        ->toBe(['message' => 'Importer definition not found.']);
});

it('returns correct response for InvalidConfigurationException with custom message', function () {
    $exception = new InvalidConfigurationException('Invalid config provided');

    $handler = Mockery::mock(Handler::class);
    $capturedResponse = null;
    $callCount = 0;

    $handler->shouldReceive('renderable')
        ->with(Mockery::type('callable'))
        ->andReturnUsing(function ($callback) use (&$capturedResponse, &$callCount, $exception) {
            $callCount++;
            if ($callCount === 3) {
                $capturedResponse = $callback($exception);
            }

            return $this;
        })
        ->atLeast();

    IngestExceptionHandler::register($handler);

    expect($capturedResponse)
        ->toBeInstanceOf(JsonResponse::class)
        ->getStatusCode()->toBe(422)
        ->and($capturedResponse->getData(true))
        ->toBe(['message' => 'Invalid config provided']);
});

it('returns default message for empty InvalidConfigurationException', function () {
    $exception = new InvalidConfigurationException();

    $handler = Mockery::mock(Handler::class);
    $capturedResponse = null;
    $callCount = 0;

    $handler->shouldReceive('renderable')
        ->with(Mockery::type('callable'))
        ->andReturnUsing(function ($callback) use (&$capturedResponse, &$callCount, $exception) {
            $callCount++;
            if ($callCount === 3) {
                $capturedResponse = $callback($exception);
            }

            return $this;
        })
        ->atLeast();

    IngestExceptionHandler::register($handler);

    expect($capturedResponse)
        ->getStatusCode()->toBe(422)
        ->and($capturedResponse->getData(true))
        ->toBe(['message' => 'Invalid ingest configuration.']);
});

it('returns correct response for ConcurrencyException', function () {
    $exception = ConcurrencyException::duplicateRetryAttempt(123);

    $handler = Mockery::mock(Handler::class);
    $capturedResponse = null;
    $callCount = 0;

    $handler->shouldReceive('renderable')
        ->with(Mockery::type('callable'))
        ->andReturnUsing(function ($callback) use (&$capturedResponse, &$callCount, $exception) {
            $callCount++;
            if ($callCount === 4) {
                $capturedResponse = $callback($exception);
            }

            return $this;
        })
        ->atLeast();

    IngestExceptionHandler::register($handler);

    expect($capturedResponse)
        ->toBeInstanceOf(JsonResponse::class)
        ->getStatusCode()->toBe(409)
        ->and($capturedResponse->getData(true))
        ->toBe(['message' => 'A retry attempt for run 123 is already in progress or completed.']);
});

it('registers all four exception handlers', function () {
    $callCount = 0;

    $handler = Mockery::mock(Handler::class);
    $handler->shouldReceive('renderable')
        ->with(Mockery::type('callable'))
        ->andReturnUsing(function () use (&$callCount, &$handler) {
            $callCount++;

            return $handler;
        })
        ->times(4);

    IngestExceptionHandler::register($handler);

    expect($callCount)->toBe(4);
});
