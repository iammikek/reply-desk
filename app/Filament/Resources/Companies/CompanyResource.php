<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies;

use App\Filament\DirectoryRecords;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Companies\RelationManagers\ContactsRelationManager;
use App\Models\Company;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Company')
                ->required()
                ->maxLength(255),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Company'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => DirectoryRecords::companies())
            ->columns([
                TextColumn::make('name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contacts')
                    ->label('Contacts')
                    ->sortable(),
            ])
            ->recordUrl(fn (array $record): string => static::getUrl('view', ['record' => $record['id']]))
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'view' => ViewCompany::route('/{record}'),
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
