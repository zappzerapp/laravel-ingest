<?php

namespace LaravelIngest\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class User extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_admin' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->password)) {
                $user->password = Hash::make('password');
            }
        });
    }

    public static array $rules = [
        'name' => 'required|string',
        'email' => 'required|email',
    ];

    public static function getRules(): array
    {
        return self::$rules;
    }
}