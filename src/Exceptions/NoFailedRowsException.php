<?php

declare(strict_types=1);

namespace LaravelIngest\Exceptions;

use Exception;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class NoFailedRowsException extends Exception implements HttpExceptionInterface
{
    public function __construct(string $message = 'The original run has no failed rows to retry.')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
