<?php

namespace App\Filament\Pages;

use App\Filament\Tables\ClosedThreadsTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class History extends Page implements HasTable
{
    use InteractsWithTable {
        getTableRecord as resolveClosedRecord;
    }

    protected static ?string $navigationLabel = 'History';

    protected static ?string $title = 'History';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 0;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function table(Table $table): Table
    {
        return ClosedThreadsTable::configure($table);
    }

    public function getTableRecord(?string $key): Model|array|null
    {
        $record = $this->resolveClosedRecord($key);

        if ($record !== null || blank($key)) {
            return $record;
        }

        return [
            'id' => $key,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }
}
