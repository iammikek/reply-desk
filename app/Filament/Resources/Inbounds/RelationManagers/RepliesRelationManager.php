<?php

namespace App\Filament\Resources\Inbounds\RelationManagers;

use App\Filament\InboundRecords;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

class RepliesRelationManager extends RelationManager
{
    protected static string $relationship = 'replies';

    protected static ?string $title = 'Replies';

    protected static bool $isLazy = false;

    #[Locked]
    public string $inboundId = '';

    public function mount(): void
    {
        $key = $this->getOwnerRecord()->getKey();

        if (is_int($key) || (is_string($key) && $key !== '')) {
            $this->inboundId = (string) $key;
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
                $id = $this->inboundId !== '' ? $this->inboundId : (string) $this->getOwnerRecord()->getKey();

                return InboundRecords::replies($id);
            })
            ->columns([
                TextColumn::make('sent')->label('Sent'),
                TextColumn::make('subject')->label('Subject')->wrap(),
                TextColumn::make('body')->label('Reply')->wrap(),
            ])
            ->paginated(false);
    }
}
