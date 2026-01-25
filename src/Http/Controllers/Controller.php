<?php

declare(strict_types=1);

namespace LaravelIngest\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    protected function authorizeAccess(): void
    {
        $this->authorize('viewIngest');
    }
}
