<?php

namespace App\Filament\Schemas;

use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

final class LogInboundForm
{
    /**
     * @return array<int, DateTimePicker|Select|TextInput>
     */
    public static function components(): array
    {
        return [
            TextInput::make('company')
                ->label('Company')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('Contact email')
                ->email()
                ->required()
                ->maxLength(255),
            DateTimePicker::make('receipt')
                ->label('Receipt')
                ->seconds(false)
                ->timezone(ReplyClock::ZONE)
                ->default(now(ReplyClock::ZONE))
                ->required(),
            Select::make('receipt_source')
                ->label('Receipt source')
                ->options([
                    ReceiptSource::MailClient->value => 'Mail client',
                    ReceiptSource::Logged->value => 'Logged',
                ])
                ->required(),
            TextInput::make('summary')
                ->label('Summary')
                ->required()
                ->maxLength(255),
        ];
    }
}
