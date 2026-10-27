<?php

namespace App\Filament\Auth\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasMaxWidth;
use Filament\Pages\Concerns\HasTopbar;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

/**
 * @property-read Schema $form
 */
class ChangePassword extends Page
{
    use HasMaxWidth;
    use HasTopbar;

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    protected static bool $shouldRegisterNavigation = false;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament-panels::pages.simple';

    protected static string $layout = 'filament-panels::components.layout.simple';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'change-password';
    }

    public function getLayout(): string
    {
        return static::$layout;
    }

    public function hasLogo(): bool
    {
        return true;
    }

    public function hasTopbar(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getLayoutData(): array
    {
        return [
            'hasTopbar' => $this->hasTopbar(),
            'maxContentWidth' => $this->getMaxContentWidth(),
            'maxWidth' => $this->getMaxWidth(),
        ];
    }

    public function mount(): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            redirect()->to(Filament::getLoginUrl());

            return;
        }

        if (! $user->mustChangePassword()) {
            redirect()->intended(Filament::getUrl());
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Change password';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Change password';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Choose a new password before the queue opens.';
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getCurrentPasswordFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->fullWidth(true),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save password')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        /** @var array{currentPassword: string, password: string} $data */
        $data = $this->form->getState();

        $user->forceFill([
            'password' => $data['password'],
            'password_changed_at' => now(),
        ])->save();

        if (request()->hasSession()) {
            request()->session()->put([
                'password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword(),
            ]);
        }

        Notification::make()
            ->title('Password changed')
            ->success()
            ->send();

        $this->redirect(Filament::getUrl());
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('currentPassword')
            ->label('Current password')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule('current_password:'.Filament::getAuthGuard())
            ->dehydrated(false);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('New password')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(Password::default())
            ->same('passwordConfirmation')
            ->dehydrateStateUsing(fn (#[SensitiveParameter] $state): string => $state);
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label('Confirm password')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->dehydrated(false);
    }
}
