<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\DeskActivity;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class Activity extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Activity';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Activity';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => DeskActivity::query()->latest('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('action')
                    ->label('Action')
                    ->searchable(),
                TextColumn::make('summary')
                    ->label('Summary')
                    ->wrap(),
                TextColumn::make('company')
                    ->label('Company')
                    ->toggleable()
                    ->color(fn (DeskActivity $record): ?string => filled($record->company_id) ? 'primary' : null)
                    ->url(fn (DeskActivity $record): ?string => filled($record->company_id)
                        ? CompanyResource::getUrl('view', ['record' => $record->company_id])
                        : null),
                TextColumn::make('contact')
                    ->label('Contact')
                    ->toggleable()
                    ->color(fn (DeskActivity $record): ?string => filled($record->contact_id) ? 'primary' : null)
                    ->url(fn (DeskActivity $record): ?string => self::contactUrl($record)),
                TextColumn::make('inbound_id')
                    ->label('Inbound')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (DeskActivity $record): ?string => self::contactUrl($record))
            ->filters([
                SelectFilter::make('action')
                    ->options(fn (): array => DeskActivity::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()),
            ])
            ->emptyStateHeading('No activity yet')
            ->emptyStateDescription('Poll, draft, send, and inbound events will appear here.')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    private static function contactUrl(DeskActivity $record): ?string
    {
        if (! filled($record->contact_id)) {
            return null;
        }

        return ContactResource::getUrl('view', ['record' => $record->contact_id]);
    }
}
