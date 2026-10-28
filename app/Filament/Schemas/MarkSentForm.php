<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Clock\ReplyClock;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

final class MarkSentForm
{
    /**
     * @return array<int, DateTimePicker|FileUpload|Textarea|TextInput>
     */
    public static function components(): array
    {
        return [
            DateTimePicker::make('sent')
                ->label('Sent')
                ->seconds(false)
                ->timezone(ReplyClock::zoneName())
                ->default(now(ReplyClock::zoneName()))
                ->required(),
            TextInput::make('subject')
                ->label('Subject')
                ->maxLength(255),
            Textarea::make('reply')
                ->label('Reply')
                ->required()
                ->rows(12),
            AttachmentField::make(),
        ];
    }
}
