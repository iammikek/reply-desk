<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Desk\DeskException;
use App\Desk\Notes\NoteStore;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class ListCompanies extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = CompanyResource::class;

    public function table(Table $table): Table
    {
        return CompanyResource::table($table);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createCompany')
                ->label('New company')
                ->button()
                ->schema([
                    TextInput::make('name')
                        ->label('Company')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    try {
                        app(NoteStore::class)->createCompany((string) $data['name']);
                    } catch (DeskException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        throw new Halt;
                    }

                    $this->flushCachedTableRecords();
                    Notification::make()->title('Company added')->success()->send();
                }),
        ];
    }
}
