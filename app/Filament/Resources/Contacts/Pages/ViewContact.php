<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Concerns\LoadsClientCorrespondence;
use App\Filament\Concerns\ResolvesExternalRecord;
use App\Filament\DirectoryRecords;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class ViewContact extends ViewRecord
{
    use LoadsClientCorrespondence;
    use ResolvesExternalRecord {
        LoadsClientCorrespondence::mount insteadof ResolvesExternalRecord;
    }

    protected static string $resource = ContactResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $contact = DirectoryRecords::contactModel((string) $key);

        abort_if(! $contact instanceof Contact, 404);

        return $contact;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function clientCorrespondenceParty(): array
    {
        return [
            (string) $this->getRecord()->getAttribute('company_id'),
            (string) $this->getRecord()->getKey(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return ContactResource::infolist($schema);
    }
}
