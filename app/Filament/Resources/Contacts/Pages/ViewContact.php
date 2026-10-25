<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\DirectoryRecords;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Company;
use App\Models\Contact;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    #[Locked]
    public string $directoryId = '';

    public function mount(int|string $record): void
    {
        $this->directoryId = (string) $record;

        parent::mount($record);
    }

    protected function resolveRecord(int|string $key): Model
    {
        $row = DirectoryRecords::contact((string) $key);

        abort_if($row === null, 404);

        if (config('reply-desk.store') !== 'hubspot') {
            $contact = Contact::query()->find($key);

            if ($contact instanceof Contact) {
                $contact->load('company');

                return $contact;
            }
        }

        $contact = new Contact([
            'name' => $row['name'],
            'email' => $row['email'],
            'company_id' => $row['company_id'],
        ]);
        $contact->setAttribute('id', $row['id']);
        $contact->setRelation('company', new Company(['name' => $row['company']]));

        return $contact;
    }

    protected function resolveRecordFromLivewire(Model $model): Model
    {
        $key = $model->getKey();

        if (! is_int($key) && (! is_string($key) || $key === '')) {
            $key = $this->directoryId;
        }

        abort_unless(is_int($key) || (is_string($key) && $key !== ''), 404);

        return $this->resolveRecord($key);
    }
}
