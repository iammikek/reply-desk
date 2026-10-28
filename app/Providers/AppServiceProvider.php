<?php

namespace App\Providers;

use App\Clock\PracticeHours;
use App\Clock\ReplyClock;
use App\Desk\Mailbox\FakeMailbox;
use App\Desk\Mailbox\ImapSmtpMailbox;
use App\Desk\Mailbox\Mailbox;
use App\Desk\Mailbox\MailboxConfigured;
use App\Desk\Mailbox\NullMailbox;
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

        $this->app->singleton(FakeMailbox::class);

        $this->app->singleton(Mailbox::class, function ($app): Mailbox {
            $config = config('reply-desk.mailbox', []);
            $driver = (string) ($config['driver'] ?? '');

            if ($driver === 'fake') {
                return $app->make(FakeMailbox::class);
            }

            if (MailboxConfigured::credentials($config)) {
                return new ImapSmtpMailbox($config);
            }

            return new NullMailbox;
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
