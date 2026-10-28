<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Desk\DeskException;
use App\Desk\Queue\ListClientCorrespondence;
use App\Filament\Concerns\ResolvesExternalRecord;
use App\Filament\DirectoryRecords;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class ViewContact extends ViewRecord
{
    use ResolvesExternalRecord;

    protected static string $resource = ContactResource::class;

    /**
     * @var list<array{at: string, kind: string, title: string, body: string, attachment: string, note_id: string}>
     */
    public array $events = [];

    protected function resolveRecord(int|string $key): Model
    {
        $contact = DirectoryRecords::contactModel((string) $key);

        abort_if(! $contact instanceof Contact, 404);

        return $contact;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $companyId = (string) $this->getRecord()->getAttribute('company_id');
        $contactId = (string) $this->getRecord()->getKey();

        try {
            $found = app(ListClientCorrespondence::class)($companyId, $contactId);
            $this->events = $found?->events ?? [];
        } catch (DeskException) {
            // Directory can render without a live note store (for example HubSpot token missing).
            $this->events = [];
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('infolist'),
            Section::make('Messages')->schema(
                $this->events === []
                    ? [Text::make('No messages with this contact yet.')]
                    : [
                        View::make('filament.thread-timeline')
                            ->viewData([
                                'client' => '',
                                'email' => '',
                                'events' => $this->events,
                                'showClient' => false,
                            ]),
                    ],
            ),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return ContactResource::infolist($schema);
    }
}
