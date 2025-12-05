<?php

namespace LaravelIngest\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

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
                $user->password = Hash::make('password');
            }
        });
    }
}