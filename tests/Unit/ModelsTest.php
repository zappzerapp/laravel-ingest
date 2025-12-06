<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use LaravelIngest\Models\IngestRun;
use Orchestra\Testbench\Factories\UserFactory;

it('belongs to a user', function () {
    $user = UserFactory::new()->create();
    $run = IngestRun::factory()->create(['user_id' => $user->id]);

    expect($run->user)->toBeInstanceOf(User::class);
    expect($run->user->id)->toBe($user->id);
});
