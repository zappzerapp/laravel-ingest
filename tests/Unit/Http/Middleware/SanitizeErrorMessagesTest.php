<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use LaravelIngest\Http\Middleware\SanitizeErrorMessages;

it('calls the next closure', function () {
    $middleware = new SanitizeErrorMessages();
    $request = new Request();

    $response = $middleware->handle($request, fn($req) => new Illuminate\Http\Response('passed'));

    expect($response->getContent())->toBe('passed');
});
