<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Desk\DeskException;
use App\Desk\Notes\NoteStore;
use App\Filament\Concerns\ResolvesExternalRecord;
use App\Filament\DirectoryRecords;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class ViewCompany extends ViewRecord
{
    use ResolvesExternalRecord;

    protected static string $resource = CompanyResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $company = DirectoryRecords::companyModel((string) $key);

        abort_if($company === null, 404);

        return $company;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createContact')
                ->label('New contact')
                ->button()
                ->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(255),
                    TextInput::make('email')->label('Email')->email()->required()->maxLength(255),
                ])
                ->action(function (array $data): void {
                    try {
                        app(NoteStore::class)->createContact(
                            $this->externalRecordKey,
                            (string) $data['name'],
                            (string) $data['email'],
                        );
                    } catch (DeskException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        throw new Halt;
                    }

                    Notification::make()->title('Contact added')->success()->send();
                }),
        ];
    }
}
