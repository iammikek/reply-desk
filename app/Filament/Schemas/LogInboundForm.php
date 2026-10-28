<?php

namespace App\Filament\Schemas;

use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\Notes\NoteStore;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

final class LogInboundForm
{
    /**
     * @return array<int, DateTimePicker|FileUpload|Radio|Select|Textarea|TextInput>
     */
    public static function components(): array
    {
        $zone = ReplyClock::zoneName();

        return [
            Select::make('company_id')
                ->label('Company')
                ->options(fn (): array => self::companyOptions())
                ->searchable()
                ->live()
                ->required()
                ->helperText('Create the company under Companies first if it is not listed.')
                ->afterStateUpdated(fn (Set $set): mixed => $set('contact_id', null)),
            Select::make('contact_id')
                ->label('Contact')
                ->options(fn (Get $get): array => self::contactOptions((string) $get('company_id')))
                ->searchable()
                ->required()
                ->helperText('Create the contact under Contacts first if it is not listed.'),
            DateTimePicker::make('receipt')
                ->label('Receipt')
                ->seconds(false)
                ->timezone($zone)
                ->default(now($zone))
                ->required()
                ->helperText('When the message arrived, in '.$zone.'.'),
            Radio::make('receipt_source')
                ->label('Receipt source')
                ->options(ReceiptSource::options())
                ->inline()
                ->required()
                ->helperText('Mail client: read in Mail. Logged: noted without the original mail. WhatsApp: arrived there.'),
            TextInput::make('subject')
                ->label('Subject')
                ->maxLength(255)
                ->helperText('Optional. The queue summary uses the subject, or the first line of the body if subject is blank.'),
            Textarea::make('body')
                ->label('Body')
                ->required()
                ->rows(8),
            AttachmentField::make(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function companyOptions(): array
    {
        $options = [];

        foreach (app(NoteStore::class)->directory() as $company) {
            $options[$company['id']] = $company['name'];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function contactOptions(string $companyId): array
    {
        if ($companyId === '') {
            return [];
        }

        foreach (app(NoteStore::class)->directory() as $company) {
            if ($company['id'] !== $companyId) {
                continue;
            }

            $options = [];

            foreach ($company['contacts'] as $contact) {
                $options[$contact['id']] = $contact['name'] !== ''
                    ? $contact['name'].' · '.$contact['email']
                    : $contact['email'];
            }

            return $options;
        }

        return [];
    }
}
