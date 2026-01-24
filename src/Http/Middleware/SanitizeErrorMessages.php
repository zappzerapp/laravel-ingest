<?php

declare(strict_types=1);

namespace LaravelIngest\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelIngest\Services\ErrorMessageService;

class SanitizeErrorMessages
{
    public function handle(Request $request, Closure $next)
    {
        ErrorMessageService::setEnvironment(!config('app.debug'));

        return $next($request);
    }
}
