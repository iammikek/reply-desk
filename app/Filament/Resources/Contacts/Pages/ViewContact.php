<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Concerns\ResolvesExternalRecord;
use App\Filament\DirectoryRecords;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewContact extends ViewRecord
{
    use ResolvesExternalRecord;

    protected static string $resource = ContactResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $contact = DirectoryRecords::contactModel((string) $key);

        abort_if(! $contact instanceof Contact, 404);

        return $contact;
    }
}
