<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Desk\DeskException;
use App\Desk\Notes\NoteStore;
use App\Filament\Resources\Contacts\ContactResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class ListContacts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ContactResource::class;

    public function table(Table $table): Table
    {
        return ContactResource::table($table);
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
            Action::make('createContact')
                ->label('New contact')
                ->button()
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(function (): array {
                            $options = [];

                            foreach (app(NoteStore::class)->directory() as $company) {
                                $options[$company['id']] = $company['name'];
                            }

                            return $options;
                        })
                        ->searchable()
                        ->required(),
                    TextInput::make('name')->label('Name')->required()->maxLength(255),
                    TextInput::make('email')->label('Email')->email()->required()->maxLength(255),
                ])
                ->action(function (array $data): void {
                    try {
                        app(NoteStore::class)->createContact(
                            (string) $data['company_id'],
                            (string) $data['name'],
                            (string) $data['email'],
                        );
                    } catch (DeskException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        throw new Halt;
                    }

                    $this->flushCachedTableRecords();
                    Notification::make()->title('Contact added')->success()->send();
                }),
        ];
    }
}
