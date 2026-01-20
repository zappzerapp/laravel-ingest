<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures\Models;

class UserWithRules extends User
{
    protected $table = 'users';

    public static function getRules(): array
    {
        return ['email' => 'email|required'];
    }
}
