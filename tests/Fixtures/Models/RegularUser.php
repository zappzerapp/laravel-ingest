<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class RegularUser extends Model
{
    protected $table = 'users';
    protected $guarded = [];
    protected $casts = [
        'is_admin' => 'boolean',
    ];

    public function roles()
    {
        return $this->belongsToMany('\LaravelIngest\Tests\Fixtures\Models\Role', 'user_role', 'user_id', 'role_id');
    }

    protected static function booted(): void
    {
        static::creating(function (RegularUser $user) {
            $user->is_admin = false;
            if (empty($user->password)) {
                $user->password = 'user_password';
            }
        });
    }
}
