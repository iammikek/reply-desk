<?php

namespace App\Filament\Pages;

use App\Clock\ReplyClock;
use App\Desk\Actions\PollMailbox;
use App\Desk\DeskException;
use App\Desk\Mailbox\Mailbox;
use App\Desk\Mailbox\MailboxConfigured;
use App\Models\MailboxState;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

/**
 * @property-read Schema $form
 */
class MailboxSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Mailbox';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'mailbox';

    protected static ?string $title = 'Mailbox';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $state = MailboxState::current();

        $this->form->fill([
            'polling_enabled' => $state->polling_enabled,
            'poll_interval_minutes' => $state->poll_interval_minutes,
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $connected = MailboxConfigured::credentials(config('reply-desk.mailbox', []));
        $mailbox = app(Mailbox::class);
        $state = MailboxState::current();
        $lastPolled = $state->last_polled_at
            ? 'Last polled '.$state->last_polled_at->timezone((string) config('reply-desk.timezone'))->format('Y-m-d H:i').'.'
            : 'Not polled yet.';
        $lastError = filled($state->last_poll_error)
            ? 'Last error: '.$state->last_poll_error
            : 'No recent poll error.';

        return $schema->components([
            Section::make('Connection')->schema([
                Text::make($connected
                    ? 'Mailbox credentials are set in the environment. Polling uses that account.'
                    : 'No mailbox credentials in the environment. Set REPLY_DESK_IMAP_* and REPLY_DESK_SMTP_* in .env. Until then, drafts stay local and send uses the Laravel mailer.'),
                Text::make($mailbox->configured() ? 'Connection ready.' : 'Connection not ready.'),
                Text::make('Test connection checks IMAP and SMTP without changing the poll cursor.'),
            ]),
            Section::make('Polling')->schema([
                Toggle::make('polling_enabled')
                    ->label('Polling enabled')
                    ->disabled(! $connected)
                    ->helperText('When enabled, the scheduler runs reply:poll-mailbox on the interval below.'),
                TextInput::make('poll_interval_minutes')
                    ->label('Poll interval (minutes)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(1440)
                    ->required()
                    ->disabled(! $connected),
                Text::make($lastPolled),
                Text::make($lastError),
            ]),
            Section::make('Practice hours')->schema([
                Text::make(app(ReplyClock::class)->hours()->summary()),
                Text::make('Receipts outside these ranges start the clock at the next open. Configure ranges in config/reply-desk.php.'),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions()),
                ]),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        $connected = MailboxConfigured::credentials(config('reply-desk.mailbox', []));

        return [
            Action::make('save')
                ->label('Save')
                ->submit('save')
                ->disabled(! $connected),
            Action::make('testConnection')
                ->label('Test connection')
                ->action('testConnection')
                ->disabled(! app(Mailbox::class)->configured()),
            Action::make('pollNow')
                ->label('Poll now')
                ->action('pollNow')
                ->disabled(! app(Mailbox::class)->configured()),
        ];
    }

    public function save(): void
    {
        if (! MailboxConfigured::credentials(config('reply-desk.mailbox', []))) {
            Notification::make()->title('Mailbox credentials are not set.')->danger()->send();

            return;
        }

        /** @var array{polling_enabled: bool, poll_interval_minutes: int} $data */
        $data = $this->form->getState();
        $state = MailboxState::current();
        $state->forceFill([
            'polling_enabled' => (bool) $data['polling_enabled'],
            'poll_interval_minutes' => max(1, (int) $data['poll_interval_minutes']),
        ])->save();

        Notification::make()->title('Mailbox settings saved.')->success()->send();
    }

    public function testConnection(): void
    {
        try {
            app(Mailbox::class)->testConnection();
        } catch (DeskException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('IMAP and SMTP connection succeeded.')->success()->send();
    }

    public function pollNow(): void
    {
        $result = app(PollMailbox::class)(force: true);

        if ($result['noop']) {
            Notification::make()->title('Mailbox is not configured.')->warning()->send();

            return;
        }

        if ($result['failed']) {
            $error = MailboxState::current()->last_poll_error ?: 'Poll failed.';
            Notification::make()->title($error)->danger()->send();

            return;
        }

        $title = "Poll finished. Kept {$result['kept']}, skipped {$result['skipped']}.";
        $reasons = PollMailbox::describeSkippedReasons($result['skipped_reasons'] ?? []);
        $notification = Notification::make()->title($title)->success();

        if ($reasons !== '') {
            $notification->body('Skipped: '.$reasons.'.');
        }

        $notification
            ->actions([
                Action::make('activity')
                    ->label('View Activity')
                    ->url(Activity::getUrl())
                    ->markAsRead(),
            ])
            ->send();
    }
}
