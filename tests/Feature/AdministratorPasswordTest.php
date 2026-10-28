<?php

use App\Filament\Auth\Pages\ChangePassword;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('sends a user who must change password to the change page', function (): void {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(ChangePassword::getUrl());
});

it('opens the queue after the password is changed', function (): void {
    $user = User::factory()->mustChangePassword()->create([
        'password' => 'old-password-1',
    ]);

    $this->actingAs($user);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'currentPassword' => 'old-password-1',
            'password' => 'new-password-1',
            'passwordConfirmation' => 'new-password-1',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect('/');

    expect($user->fresh()->mustChangePassword())->toBeFalse()
        ->and($user->fresh()->password_changed_at)->not->toBeNull();

    $this->get('/')->assertSuccessful()->assertSee('Open queue');
});

it('refuses a wrong current password', function (): void {
    $user = User::factory()->mustChangePassword()->create([
        'password' => 'old-password-1',
    ]);

    $this->actingAs($user);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'currentPassword' => 'wrong-password',
            'password' => 'new-password-1',
            'passwordConfirmation' => 'new-password-1',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);

    expect($user->fresh()->mustChangePassword())->toBeTrue();
});

it('opens the queue when the password was already changed', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertSuccessful()
        ->assertSee('Open queue')
        ->assertDontSee('Choose a new password before the queue opens.');
});

it('sends a password reset link and accepts a new password', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'admin@example.com',
    ]);

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => 'admin@example.com'])
        ->call('request')
        ->assertNotified();

    Notification::assertSentTo($user, ResetPasswordNotification::class);

    $token = Password::broker()->createToken($user);

    Livewire::test(ResetPassword::class, [
        'email' => 'admin@example.com',
        'token' => $token,
    ])
        ->fillForm([
            'password' => 'reset-password-1',
            'passwordConfirmation' => 'reset-password-1',
        ])
        ->call('resetPassword');

    expect($user->fresh()->mustChangePassword())->toBeFalse()
        ->and(password_verify('reset-password-1', $user->fresh()->password))->toBeTrue();
});
