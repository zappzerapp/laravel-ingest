<?php

declare(strict_types=1);

use LaravelIngest\Enums\DuplicateStrategy;
use LaravelIngest\IngestConfig;
use LaravelIngest\Models\IngestRun;
use LaravelIngest\Services\RowProcessor;
use LaravelIngest\Tests\Fixtures\Models\Role;
use LaravelIngest\Tests\Fixtures\Models\User;

it('adds relateMany configuration', function () {
    $config = IngestConfig::for('\LaravelIngest\Tests\Fixtures\Models\User')
        ->relateMany('role_slugs', 'roles', '\LaravelIngest\Tests\Fixtures\Models\Role', 'slug', ',');

    expect($config->manyRelations)->toHaveKey('role_slugs')
        ->and($config->manyRelations['role_slugs'])->toMatchArray([
            'relation' => 'roles',
            'model' => '\LaravelIngest\Tests\Fixtures\Models\Role',
            'key' => 'slug',
            'separator' => ',',
        ]);
});

it('throws exception when relating to non-model class', function () {
    IngestConfig::for('\LaravelIngest\Tests\Fixtures\Models\User')
        ->relateMany('tags', 'tags', 'stdClass', 'name', ',');
})->throws(LaravelIngest\Exceptions\InvalidConfigurationException::class);

it('can set compare timestamp configuration', function () {
    $config = IngestConfig::for('\LaravelIngest\Tests\Fixtures\Models\User')
        ->compareTimestamp('import_date', 'updated_at');

    expect($config->timestampComparison)->toMatchArray([
        'source_column' => 'import_date',
        'db_column' => 'updated_at',
    ]);
});

it('defaults db column to updated_at', function () {
    $config = IngestConfig::for('\LaravelIngest\Tests\Fixtures\Models\User')
        ->compareTimestamp('import_date');

    expect($config->timestampComparison['db_column'])->toBe('updated_at');
});

it('has UPDATE_IF_NEWER duplicate strategy', function () {
    expect(DuplicateStrategy::UPDATE_IF_NEWER)->toBeInstanceOf(DuplicateStrategy::class)
        ->and(DuplicateStrategy::UPDATE_IF_NEWER->value)->toBe('update_if_newer');
});

it('syncs many-to-many relations from comma separated values', function () {
    $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
    $editorRole = Role::create(['name' => 'Editor', 'slug' => 'editor']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('role_slugs', 'roles', Role::class, 'slug', ',');

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com', 'role_slugs' => 'admin,editor']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $user = User::where('email', 'john@test.com')->first();
    expect($user->roles)->toHaveCount(2)
        ->and($user->roles->pluck('slug')->toArray())->toContain('admin', 'editor');
});

it('skips many relation sync when source field is missing', function () {
    Role::create(['name' => 'Admin', 'slug' => 'admin']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('role_slugs', 'roles', Role::class, 'slug', ',');

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $user = User::where('email', 'john@test.com')->first();
    expect($user->roles)->toHaveCount(0);
});

it('skips many relation sync when value is empty', function () {
    Role::create(['name' => 'Admin', 'slug' => 'admin']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('role_slugs', 'roles', Role::class, 'slug', ',');

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com', 'role_slugs' => '']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $user = User::where('email', 'john@test.com')->first();
    expect($user->roles)->toHaveCount(0);
});

it('skips many relation sync when separator results in empty values', function () {
    Role::create(['name' => 'Admin', 'slug' => 'admin']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('role_slugs', 'roles', Role::class, 'slug', ',');

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com', 'role_slugs' => ',  ,  ']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $user = User::where('email', 'john@test.com')->first();
    expect($user->roles)->toHaveCount(0);
});

it('handles many relation sync with nested source field', function () {
    $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('meta.roles', 'roles', Role::class, 'slug', ',');

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com', 'meta' => ['roles' => 'admin']]]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $user = User::where('email', 'john@test.com')->first();
    expect($user->roles)->toHaveCount(1)
        ->and($user->roles->first()->slug)->toBe('admin');
});

it('prefetches many relations and uses cache', function () {
    $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
    $editorRole = Role::create(['name' => 'Editor', 'slug' => 'editor']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('role_slugs', 'roles', Role::class, 'slug', ',');

    $chunk = [
        ['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com', 'role_slugs' => 'admin,editor']],
        ['number' => 2, 'data' => ['name' => 'Jane', 'email' => 'jane@test.com', 'role_slugs' => 'admin']],
    ];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $john = User::where('email', 'john@test.com')->first();
    $jane = User::where('email', 'jane@test.com')->first();

    expect($john->roles)->toHaveCount(2)
        ->and($jane->roles)->toHaveCount(1);
});

it('handles empty many relation cache gracefully', function () {
    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('role_slugs', 'roles', Role::class, 'slug', ',');

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com', 'role_slugs' => 'nonexistent']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $user = User::where('email', 'john@test.com')->first();
    expect($user->roles)->toHaveCount(0);
});

it('skips prefetching many relations when no data present', function () {
    Role::create(['name' => 'Admin', 'slug' => 'admin']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('role_slugs', 'roles', Role::class, 'slug', ',');

    $chunk = [['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com']]];

    (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    $user = User::where('email', 'john@test.com')->first();
    expect($user)->not->toBeNull();
});

it('skips prefetching many relations when all values are empty after splitting', function () {
    Role::create(['name' => 'Admin', 'slug' => 'admin']);

    $config = IngestConfig::for(User::class)
        ->map('name', 'name')
        ->map('email', 'email')
        ->relateMany('role_slugs', 'roles', Role::class, 'slug', ',');

    $chunk = [
        ['number' => 1, 'data' => ['name' => 'John', 'email' => 'john@test.com', 'role_slugs' => ',,']],
        ['number' => 2, 'data' => ['name' => 'Jane', 'email' => 'jane@test.com', 'role_slugs' => ',']],
    ];

    $result = (new RowProcessor())->processChunk(
        IngestRun::factory()->create(),
        $config,
        $chunk,
        false
    );

    expect($result['successful'])->toBe(2);

    $john = User::where('email', 'john@test.com')->first();
    $jane = User::where('email', 'jane@test.com')->first();

    expect($john)->not->toBeNull()
        ->and($jane)->not->toBeNull()
        ->and($john->roles)->toHaveCount(0)
        ->and($jane->roles)->toHaveCount(0);
});
