<?php

namespace App\Providers;

use App\Desk\DatabaseNoteStore;
use App\Desk\HubSpotNoteStore;
use App\Desk\NoteStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
