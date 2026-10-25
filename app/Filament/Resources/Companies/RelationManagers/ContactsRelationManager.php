<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Filament\DirectoryRecords;
use App\Filament\Resources\Contacts\ContactResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Contacts';

    protected static bool $isLazy = false;

    #[Locked]
    public string $directoryId = '';

    public function mount(): void
    {
        $key = $this->getOwnerRecord()->getKey();

        if (is_int($key) || (is_string($key) && $key !== '')) {
            $this->directoryId = (string) $key;
        }

        parent::mount();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->heading(static::getTitle($this->getOwnerRecord(), $this->getPageClass()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $company = DirectoryRecords::company($this->directoryId !== '' ? $this->directoryId : (string) $this->getOwnerRecord()->getKey());

                return $company['contacts'] ?? [];
            })
            ->columns([
                TextColumn::make('name')->label('Name')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
            ])
            ->recordUrl(fn (array $record): string => ContactResource::getUrl('view', ['record' => $record['id']]))
            ->paginated(false);
    }
}
