<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use Filament\Forms\Components\FileUpload;

final class AttachmentField
{
    public static function make(): FileUpload
    {
        return FileUpload::make('attachment')
            ->label('Attachment')
            ->disk('local')
            ->directory('note-uploads')
            ->storeFileNamesIn('attachment_name')
            ->nullable();
    }
}
