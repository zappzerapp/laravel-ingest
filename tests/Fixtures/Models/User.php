<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    public static array $rules = [
        'name' => 'required|string',
        'email' => 'required|email',
    ];
    protected $guarded = [];
    protected $casts = [
        'is_admin' => 'boolean',
    ];

    public static function getRules(): array
    {
        return self::$rules;
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->password)) {
                // FIX: The Hash facade is not always available in this unit test
                // context, causing a silent RuntimeException. For testing purposes,
                // a plain string is sufficient and removes this instability.
                $user->password = 'password';
            }
        });
    }
}
