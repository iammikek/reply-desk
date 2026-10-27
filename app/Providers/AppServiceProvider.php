<?php

namespace App\Providers;

use App\Clock\PracticeHours;
use App\Clock\ReplyClock;
use App\Desk\Notes\DatabaseNoteStore;
use App\Desk\Notes\HubSpotNoteStore;
use App\Desk\Notes\NoteStore;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
