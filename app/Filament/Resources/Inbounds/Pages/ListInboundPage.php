<?php

namespace App\Filament\Resources\Inbounds\Pages;

use App\Filament\Resources\Inbounds\InboundResource;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

abstract class ListInboundPage extends Page implements HasTable
{
    use InteractsWithTable {
        getTableRecord as resolveInboundRecord;
    }

    protected static string $resource = InboundResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected Width|string|null $maxContentWidth = Width::Full;

    abstract public function table(Table $table): Table;

    public function getTableRecord(?string $key): Model|array|null
    {
        $record = $this->resolveInboundRecord($key);

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
