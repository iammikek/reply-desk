<?php

namespace App\Filament\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

final class SendForm
{
    /**
     * @return array<int, FileUpload|Textarea|TextInput>
     */
    public static function components(): array
    {
        return [
            TextInput::make('subject')
                ->label('Subject')
                ->required()
                ->maxLength(255),
            Textarea::make('body')
                ->label('Reply')
                ->required()
                ->rows(12),
            AttachmentField::make(),
        ];
    }
}
