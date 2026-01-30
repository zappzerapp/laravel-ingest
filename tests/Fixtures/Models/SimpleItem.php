<?php

declare(strict_types=1);

namespace LaravelIngest\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class SimpleItem extends Model
{
    public $timestamps = false;
    protected $guarded = [];
}
