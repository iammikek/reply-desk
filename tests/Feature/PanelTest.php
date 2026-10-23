<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the reply desk login', function () {
    $this->get('/login')->assertSuccessful();
});

it('shows Mike after sign-in', function () {
    $this->seed();

    $this->actingAs(User::query()->where('email', 'mike@automica.io')->first())
        ->get('/')
        ->assertSuccessful()
        ->assertSee('Mike Karthauser');
});
