<?php

declare(strict_types=1);

namespace App\Filament;

use App\Desk\DeskException;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

final class DeskNotify
{
    public static function halt(DeskException $exception): never
    {
        Notification::make()->title($exception->getMessage())->danger()->send();

        throw new Halt;
    }
}
