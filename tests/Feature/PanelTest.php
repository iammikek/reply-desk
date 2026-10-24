<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the reply desk login', function () {
    $this->get('/login')->assertSuccessful();
});

it('shows the seeded user after sign-in', function () {
    $this->seed();

    expect(User::query()->where('email', 'mike@automica.io')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'admin@example.com')->value('name'))->toBe('Administrator');

    $this->actingAs(User::query()->where('email', 'admin@example.com')->first())
        ->get('/')
        ->assertSuccessful()
        ->assertSee('Administrator');
});
