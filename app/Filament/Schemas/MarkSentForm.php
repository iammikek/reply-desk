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
        $zone = ReplyClock::zoneName();

        return [
            DateTimePicker::make('sent')
                ->label('Sent')
                ->seconds(false)
                ->timezone($zone)
                ->default(now($zone))
                ->required()
                ->helperText('When the reply left Mail (or another client), in '.$zone.'. Use Send on the row when the desk should send it.'),
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
