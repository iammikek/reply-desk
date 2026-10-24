<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the reply desk login', function () {
    $this->get('/login')
        ->assertSuccessful()
        ->assertSee('The open queue for practice client email.')
        ->assertSee('A draft stays on the desk. It does not stop the clock.')
        ->assertSee('Send the reply from here, or mark it sent after it goes from Mail. A file on the note is stored in HubSpot.')
        ->assertSee('Closed threads stay on History.')
        ->assertSee('Read the case study')
        ->assertSee('/case-study', false)
        ->assertSee('Sign in');
});

it('shows the case study without signing in', function () {
    $this->get('/case-study')
        ->assertSuccessful()
        ->assertSee('Why this desk exists')
        ->assertSee('Due time is calculated when the page is opened')
        ->assertSee('Send delivers the reply and keeps the paste')
        ->assertSee('the name of a file opens it')
        ->assertSee('Cursor cannot send.')
        ->assertSee('Open queue')
        ->assertSee('The same actions from Cursor')
        ->assertDontSee('Dashboard')
        ->assertDontSee('fi-sidebar', false);
});

it('shows the seeded user after sign-in', function () {
    $this->seed();

    expect(User::query()->where('email', 'mike@automica.io')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'admin@example.com')->value('name'))->toBe('Administrator');

    $this->actingAs(User::query()->where('email', 'admin@example.com')->first())
        ->get('/')
        ->assertSuccessful()
        ->assertSee('Open queue')
        ->assertSee('Administrator')
        ->assertDontSee('Dashboard')
        ->assertDontSee('Case study');
});
