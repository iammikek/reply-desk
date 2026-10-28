<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inbounds\Pages;

use App\Filament\Tables\ClosedThreadsTable;
use Filament\Tables\Table;

class ListHistory extends ListInboundPage
{
    protected static ?string $navigationLabel = 'History';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return ClosedThreadsTable::configure($table);
    }
}
