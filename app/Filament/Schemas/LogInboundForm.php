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
        return [
            Select::make('company_id')
                ->label('Company')
                ->options(fn (): array => self::companyOptions())
                ->searchable()
                ->live()
                ->required()
                ->afterStateUpdated(fn (Set $set) => $set('contact_id', null)),
            Select::make('contact_id')
                ->label('Contact')
                ->options(fn (Get $get): array => self::contactOptions((string) $get('company_id')))
                ->searchable()
                ->required(),
            DateTimePicker::make('receipt')
                ->label('Receipt')
                ->seconds(false)
                ->timezone(ReplyClock::ZONE)
                ->default(now(ReplyClock::ZONE))
                ->required(),
            Radio::make('receipt_source')
                ->label('Receipt source')
                ->options([
                    ReceiptSource::MailClient->value => 'Mail client',
                    ReceiptSource::Logged->value => 'Logged',
                ])
                ->inline()
                ->required(),
            TextInput::make('subject')
                ->label('Subject')
                ->required()
                ->maxLength(255),
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
