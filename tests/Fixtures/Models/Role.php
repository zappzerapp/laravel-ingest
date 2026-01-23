<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $guarded = [];

    public function users()
    {
        return $this->belongsToMany('\LaravelIngest\Tests\Fixtures\Models\User', 'user_role', 'role_id', 'user_id');
    }
}
