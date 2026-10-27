<?php

use App\Filament\Auth\Pages\ChangePassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the reply desk login', function () {
    $this->get('/login')
        ->assertSuccessful()
        ->assertSee('The open queue for practice client email.')
        ->assertSee('A draft stays on the desk. It does not stop the clock.')
        ->assertSee('Companies and contacts come from HubSpot, or from the local tables.')
        ->assertSee('A file stays on that email.')
        ->assertSee('Closed threads stay on History.')
        ->assertSee('Read the case study')
        ->assertSee('https://automica.io/reply-desk', false)
        ->assertDontSee('/case-study', false)
        ->assertSee('Sign in');
});

it('leaves the in-app case study', function () {
    $this->get('/case-study')->assertNotFound();
});

it('shows the seeded user after sign-in', function () {
    $this->seed();

    $administrator = User::query()->where('email', 'admin@example.com')->first();

    expect(User::query()->where('email', 'mike@automica.io')->exists())->toBeFalse()
        ->and($administrator?->name)->toBe('Administrator')
        ->and($administrator?->mustChangePassword())->toBeTrue();

    $this->actingAs($administrator)
        ->get('/')
        ->assertRedirect(ChangePassword::getUrl());
});

it('shows the outside hours banner on saturday', function () {
    $this->travelTo(london('2026-10-24 15:00'));
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertSuccessful()
        ->assertSee('Outside practice hours. Next open Monday 08:00.');
});

it('hides the outside hours banner inside the window', function () {
    $this->travelTo(london('2026-10-20 10:00'));
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertSuccessful()
        ->assertDontSee('Outside practice hours');
});
