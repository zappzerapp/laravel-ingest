<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $guarded = [];
    protected $casts = [
        'is_admin' => 'boolean',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_role', 'user_id', 'role_id');
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->password)) {
                $user->password = 'password';
            }
        });
    }
}
