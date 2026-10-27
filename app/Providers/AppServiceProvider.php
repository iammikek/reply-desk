<?php

namespace App\Providers;

use App\Clock\PracticeHours;
use App\Clock\ReplyClock;
use App\Desk\Notes\DatabaseNoteStore;
use App\Desk\Notes\HubSpotNoteStore;
use App\Desk\Notes\NoteStore;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PracticeHours::class, function (): PracticeHours {
            return PracticeHours::fromConfig(config('reply-desk'));
        });

        $this->app->singleton(ReplyClock::class, function ($app): ReplyClock {
            return new ReplyClock($app->make(PracticeHours::class));
        });

        $this->app->singleton(NoteStore::class, function ($app): NoteStore {
            if (config('reply-desk.store') === 'hubspot') {
                return $app->make(HubSpotNoteStore::class);
            }

            return $app->make(DatabaseNoteStore::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(8));

        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['password_changed_at' => now()])->save();
            }
        });
    }
}
