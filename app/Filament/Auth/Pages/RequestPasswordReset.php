<?php

namespace App\Filament\Auth\Pages;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as FilamentRequestPasswordReset;
use Filament\Notifications\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

class RequestPasswordReset extends FilamentRequestPasswordReset
{
    public function request(): void
    {
        try {
            parent::request();
        } catch (TransportException|Throwable $exception) {
            if (! $exception instanceof TransportException && ! $exception->getPrevious() instanceof TransportException) {
                throw $exception;
            }

            report($exception);

            Notification::make()
                ->title('The reset email could not be sent.')
                ->body('The host mailer refused the message. Try again later, or ask an administrator to set a working mail path.')
                ->danger()
                ->send();
        }
    }
}
