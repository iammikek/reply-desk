<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts;

use App\Filament\DirectoryRecords;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Models\Contact;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUser;

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('email')->label('Email')->email()->required()->maxLength(255),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Name'),
            TextEntry::make('email')->label('Email'),
            TextEntry::make('company.name')
                ->label('Company')
                ->url(fn (Contact $record): string => CompanyResource::getUrl('view', ['record' => $record->company_id])),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => DirectoryRecords::contacts())
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('company')
                    ->label('Company')
                    ->searchable()
                    ->url(fn (array $record): string => CompanyResource::getUrl('view', ['record' => $record['company_id']])),
            ])
            ->recordUrl(fn (array $record): string => static::getUrl('view', ['record' => $record['id']]))
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'view' => ViewContact::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
