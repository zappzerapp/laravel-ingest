<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class AdminUser extends Model
{
    protected $table = 'users';
    protected $guarded = [];
    protected $casts = [
        'is_admin' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (AdminUser $user) {
            $user->is_admin = true;
            if (empty($user->password)) {
                $user->password = 'admin_password';
            }
        });
    }
}
