<?php

namespace App\Filament\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

final class DraftForm
{
    /**
     * @return array<int, Textarea|TextInput>
     */
    public static function components(): array
    {
        return [
            TextInput::make('subject')
                ->label('Subject')
                ->required()
                ->maxLength(255),
            Textarea::make('body')
                ->label('Body')
                ->required()
                ->rows(8),
        ];
    }
}
