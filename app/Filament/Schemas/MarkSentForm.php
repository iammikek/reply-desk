<?php

namespace App\Filament\Schemas;

use App\Clock\ReplyClock;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

final class MarkSentForm
{
    /**
     * @return array<int, DateTimePicker|Textarea|TextInput>
     */
    public static function components(): array
    {
        return [
            DateTimePicker::make('sent')
                ->label('Sent')
                ->seconds(false)
                ->timezone(ReplyClock::ZONE)
                ->default(now(ReplyClock::ZONE))
                ->required(),
            TextInput::make('subject')
                ->label('Subject')
                ->required()
                ->maxLength(255),
            Textarea::make('reply')
                ->label('Reply')
                ->required()
                ->rows(12),
        ];
    }
}
