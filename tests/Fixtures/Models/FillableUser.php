<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class FillableUser extends Model
{
    protected $table = 'users';
    protected $fillable = ['email', 'name'];
    protected $hidden = ['password'];

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            if (empty($user->password)) {
                $user->password = 'default_password';
            }
        });
    }
}
