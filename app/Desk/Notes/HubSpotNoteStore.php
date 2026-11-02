<?php

namespace App\Desk\Notes;

use App\Desk\DeskException;

final class HubSpotNoteStore implements NoteStore
{
    private bool $portalChecked = false;

    private ?HubSpotClient $client = null;

    private ?HubSpotCrmLookup $crm = null;

    private ?HubSpotFiles $files = null;

    private ?HubSpotPendingNotes $pending = null;

    private ?HubSpotDirectory $directory = null;

    private ?HubSpotNoteReader $reader = null;

    public function assertPortal(): void
    {
        if ($this->portalChecked) {
            return;
        }

        $expected = (string) config('reply-desk.portal_id');
        $token = (string) config('reply-desk.token');

        if ($expected === '' || $token === '') {
            throw new DeskException('HubSpot portal id and access token are required.');
        }

        $account = $this->client()->send('GET', '/account-info/v3/details');
        $actual = (string) ($account['portalId'] ?? '');

        if ($actual !== $expected) {
            throw new DeskException("HubSpot portal {$actual} does not match the configured portal.");
        }

        $this->portalChecked = true;
    }

    public function findCompany(string $name): Party
    {
        $name = trim($name);

        if ($name === '') {
            throw new DeskException('A company is required.');
        }

        $results = $this->crm()->companyResults('EQ', [$name]);

        if ($results === []) {
            $tokens = array_values(array_filter(
                preg_split('/\s+/', $name) ?: [],
                fn (string $token): bool => $token !== '',
            ));

            if ($tokens !== []) {
                $results = $this->crm()->companyResults('CONTAINS_TOKEN', $tokens);
            }
        }

        if ($results === []) {
            throw new DeskException("No company matched \"{$name}\".");
        }

        $match = $this->crm()->one($results, 'company');

        return new Party((string) $match['id'], (string) ($match['properties']['name'] ?? $name));
    }

    public function findContact(string $email): Party
    {
        $email = strtolower(trim($email));

        if ($email === '' || ! str_contains($email, '@')) {
            throw new DeskException('A contact needs an email address.');
        }

        $found = $this->client()->send('POST', '/crm/objects/2026-03/contacts/search', [
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'email',
                    'operator' => 'EQ',
                    'value' => $email,
                ]],
            ]],
            'properties' => ['email', 'firstname', 'lastname'],
            'limit' => 10,
        ]);

        $match = $this->crm()->one($found['results'] ?? [], 'contact');
        $properties = $match['properties'] ?? [];

        return new Party(
            (string) $match['id'],
            trim((($properties['firstname'] ?? '')).' '.(($properties['lastname'] ?? ''))),
            (string) ($properties['email'] ?? $email),
        );
    }

    public function createCompany(string $name): Party
    {
        $name = trim($name);

        if ($name === '') {
            throw new DeskException('A company is required.');
        }

        $this->assertPortal();
        $results = $this->crm()->companyResults('EQ', [$name]);

        if (count($results) > 1) {
            $this->crm()->one($results, 'company');
        }

        if ($results !== []) {
            throw new DeskException('That company is already there.');
        }

        $created = $this->client()->send('POST', '/crm/objects/2026-03/companies', [
            'properties' => ['name' => $name],
        ]);
        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw new DeskException('HubSpot did not return a company id.');
        }

        $this->directoryStore()->forget();

        return new Party($id, $name);
    }

    public function createContact(string $companyId, string $name, string $email): Party
    {
        $companyId = trim($companyId);
        $name = trim($name);
        $email = strtolower(trim($email));

        if ($companyId === '') {
            throw new DeskException('A company is required.');
        }

        if ($name === '') {
            throw new DeskException('A contact is required.');
        }

        if ($email === '' || ! str_contains($email, '@')) {
            throw new DeskException('A contact needs an email address.');
        }

        $this->assertPortal();
        $found = $this->client()->send('POST', '/crm/objects/2026-03/contacts/search', [
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'email',
                    'operator' => 'EQ',
                    'value' => $email,
                ]],
            ]],
            'properties' => ['email'],
            'limit' => 10,
        ]);
        $results = $found['results'] ?? [];

        if (count($results) > 1) {
            $this->crm()->one($results, 'contact');
        }

        if ($results !== []) {
            throw new DeskException('That contact is already there.');
        }

        [$firstName, $lastName] = $this->personName($name);
        $properties = [
            'email' => $email,
            'firstname' => $firstName,
        ];

        if ($lastName !== '') {
            $properties['lastname'] = $lastName;
        }

        $created = $this->client()->send('POST', '/crm/objects/2026-03/contacts', [
            'properties' => $properties,
        ]);
        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw new DeskException('HubSpot did not return a contact id.');
        }

        $this->client()->send('PUT', "/crm/objects/2026-03/contact/{$id}/associations/default/company/{$companyId}");
        $this->directoryStore()->forget();

        return new Party($id, $name, $email);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function personName(string $name): array
    {
        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    public function writeNote(string $body, string $companyId, string $contactId, ?NoteFile $file = null): string
    {
        $this->assertPortal();

        $properties = [
            'hs_note_body' => $body,
            'hs_timestamp' => (string) now()->getTimestampMs(),
        ];

        $fileId = null;

        if ($file instanceof NoteFile) {
            $fileId = $this->client()->upload($file);
            $properties['hs_attachment_ids'] = $fileId;
        }

        $note = $this->client()->send('POST', '/crm/objects/2026-03/notes', [
            'properties' => $properties,
        ]);

        $id = (string) ($note['id'] ?? '');

        if ($id === '') {
            throw new DeskException('HubSpot did not return a note id.');
        }

        $this->client()->send('PUT', "/crm/objects/2026-03/note/{$id}/associations/default/company/{$companyId}");
        $this->client()->send('PUT', "/crm/objects/2026-03/note/{$id}/associations/default/contact/{$contactId}");

        $stored = $this->labelled($id, $body, $companyId, $contactId, $file?->name, $fileId);
        $this->reader()->recordWritten($stored);

        return $id;
    }

    public function replaceNote(string $id, string $body): void
    {
        $this->assertPortal();

        $this->client()->send('PATCH', '/crm/objects/2026-03/notes/'.$id, [
            'properties' => [
                'hs_note_body' => $body,
            ],
        ]);

        $current = $this->reader()->known($id);
        $stored = new StoredNote(
            $id,
            $body,
            $current?->companyId ?? '',
            $current?->companyName ?? '',
            $current?->contactId ?? '',
            $current?->contactName ?? '',
            $current?->contactEmail ?? '',
            $current?->attachmentName,
            $current?->attachmentId,
        );

        $this->reader()->recordReplacement($stored);
    }

    public function attachmentFor(string $noteId): ?NoteAttachment
    {
        foreach ($this->notes() as $note) {
            if ($note->id !== $noteId) {
                continue;
            }

            return $this->files()->attachment($note);
        }

        return null;
    }

    public function notes(): array
    {
        return $this->reader()->all($this->assertPortal(...));
    }

    private function labelled(string $id, string $body, string $companyId, string $contactId, ?string $attachmentName = null, ?string $attachmentId = null): StoredNote
    {
        $companyName = '';
        $contactName = '';
        $contactEmail = '';

        foreach ($this->directoryStore()->cached() ?? [] as $company) {
            if ($company['id'] !== $companyId) {
                continue;
            }

            $companyName = $company['name'];

            foreach ($company['contacts'] as $contact) {
                if ($contact['id'] !== $contactId) {
                    continue;
                }

                $contactName = $contact['name'];
                $contactEmail = $contact['email'];

                break 2;
            }

            break;
        }

        if ($companyName === '') {
            $companyName = $this->crm()->names('companies', [$companyId], 'name')[$companyId] ?? '';
        }

        if ($contactEmail === '') {
            $person = $this->crm()->contacts([$contactId])[$contactId] ?? null;

            if ($person !== null) {
                $contactName = $person['name'];
                $contactEmail = $person['email'];
            }
        }

        return new StoredNote($id, $body, $companyId, $companyName, $contactId, $contactName, $contactEmail, $attachmentName, $attachmentId);
    }

    public function directory(): array
    {
        return $this->directoryStore()->all($this->assertPortal(...));
    }

    private function client(): HubSpotClient
    {
        return $this->client ??= new HubSpotClient;
    }

    private function crm(): HubSpotCrmLookup
    {
        return $this->crm ??= new HubSpotCrmLookup($this->client());
    }

    private function files(): HubSpotFiles
    {
        return $this->files ??= new HubSpotFiles($this->client());
    }

    private function pending(): HubSpotPendingNotes
    {
        return $this->pending ??= new HubSpotPendingNotes;
    }

    private function directoryStore(): HubSpotDirectory
    {
        return $this->directory ??= new HubSpotDirectory($this->client(), $this->crm());
    }

    private function reader(): HubSpotNoteReader
    {
        return $this->reader ??= new HubSpotNoteReader(
            $this->client(),
            $this->crm(),
            $this->files(),
            $this->pending(),
        );
    }
}
