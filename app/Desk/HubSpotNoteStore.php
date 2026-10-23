<?php

namespace App\Desk;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class HubSpotNoteStore implements NoteStore
{
    private bool $portalChecked = false;

    public function assertPortal(): void
    {
        if ($this->portalChecked) {
            return;
        }

        $expected = (string) config('reply-desk.portal_id');
        $token = $this->token();

        if ($expected === '' || $token === '') {
            throw new DeskException('HubSpot portal id and access token are required.');
        }

        $account = $this->send('GET', '/account-info/v3/details');
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

        $found = $this->send('POST', '/crm/objects/2026-03/companies/search', [
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'name',
                    'operator' => 'EQ',
                    'value' => $name,
                ]],
            ]],
            'properties' => ['name'],
            'limit' => 10,
        ]);

        $match = $this->one($found['results'] ?? [], 'company');

        return new Party((string) $match['id'], (string) ($match['properties']['name'] ?? $name));
    }

    public function findContact(string $email): Party
    {
        $email = strtolower(trim($email));

        if ($email === '' || ! str_contains($email, '@')) {
            throw new DeskException('A contact needs an email address.');
        }

        $found = $this->send('POST', '/crm/objects/2026-03/contacts/search', [
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

        $match = $this->one($found['results'] ?? [], 'contact');
        $properties = $match['properties'] ?? [];

        return new Party(
            (string) $match['id'],
            trim(((string) ($properties['firstname'] ?? '')).' '.((string) ($properties['lastname'] ?? ''))),
            (string) ($properties['email'] ?? $email),
        );
    }

    public function writeNote(string $body, string $companyId, string $contactId): string
    {
        $this->assertPortal();

        $note = $this->send('POST', '/crm/objects/2026-03/notes', [
            'properties' => [
                'hs_note_body' => $body,
                'hs_timestamp' => (string) now()->getTimestampMs(),
            ],
        ]);

        $id = (string) ($note['id'] ?? '');

        if ($id === '') {
            throw new DeskException('HubSpot did not return a note id.');
        }

        $this->send('PUT', "/crm/objects/2026-03/note/{$id}/associations/default/company/{$companyId}");
        $this->send('PUT', "/crm/objects/2026-03/note/{$id}/associations/default/contact/{$contactId}");

        return $id;
    }

    public function notes(): array
    {
        $this->assertPortal();

        $raw = $this->searchClockNotes();
        $companies = [];
        $contacts = [];

        foreach ($raw as $note) {
            $companyId = $this->onlyId($note['associations']['companies']['results'] ?? []);
            $contactId = $this->onlyId($note['associations']['contacts']['results'] ?? []);

            if ($companyId === null || $contactId === null) {
                continue;
            }

            $companies[$companyId] = $companyId;
            $contacts[$contactId] = $contactId;
        }

        $companyNames = $this->names('companies', array_values($companies), 'name');
        $contactRecords = $this->contacts(array_values($contacts));
        $stored = [];

        foreach ($raw as $note) {
            $companyId = $this->onlyId($note['associations']['companies']['results'] ?? []);
            $contactId = $this->onlyId($note['associations']['contacts']['results'] ?? []);

            if ($companyId === null || $contactId === null) {
                continue;
            }

            $contact = $contactRecords[$contactId] ?? ['name' => '', 'email' => ''];

            $stored[] = new StoredNote(
                (string) $note['id'],
                (string) ($note['properties']['hs_note_body'] ?? ''),
                $companyId,
                $companyNames[$companyId] ?? '',
                $contactId,
                $contact['name'],
                $contact['email'],
            );
        }

        return $stored;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchClockNotes(): array
    {
        $notes = [];
        $after = null;

        for ($page = 0; $page < 10; $page++) {
            $body = [
                'filterGroups' => [[
                    'filters' => [[
                        'propertyName' => 'hs_note_body',
                        'operator' => 'CONTAINS_TOKEN',
                        'value' => 'Clock',
                    ]],
                ]],
                'properties' => ['hs_note_body'],
                'limit' => 100,
            ];

            if ($after !== null) {
                $body['after'] = $after;
            }

            $found = $this->send('POST', '/crm/objects/2026-03/notes/search', $body);

            foreach ($found['results'] ?? [] as $note) {
                $full = $this->send('GET', '/crm/objects/2026-03/notes/'.$note['id'].'?associations=companies,contacts');
                $notes[] = $full;
            }

            $after = $found['paging']['next']['after'] ?? null;

            if ($after === null) {
                break;
            }
        }

        return $notes;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function names(string $object, array $ids, string $property): array
    {
        if ($ids === []) {
            return [];
        }

        $found = $this->send('POST', "/crm/objects/2026-03/{$object}/batch/read", [
            'properties' => [$property],
            'inputs' => array_map(fn (string $id): array => ['id' => $id], $ids),
        ]);

        $names = [];

        foreach ($found['results'] ?? [] as $row) {
            $names[(string) $row['id']] = (string) ($row['properties'][$property] ?? '');
        }

        return $names;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{name: string, email: string}>
     */
    private function contacts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = $this->send('POST', '/crm/objects/2026-03/contacts/batch/read', [
            'properties' => ['email', 'firstname', 'lastname'],
            'inputs' => array_map(fn (string $id): array => ['id' => $id], $ids),
        ]);

        $contacts = [];

        foreach ($found['results'] ?? [] as $row) {
            $properties = $row['properties'] ?? [];
            $contacts[(string) $row['id']] = [
                'name' => trim(((string) ($properties['firstname'] ?? '')).' '.((string) ($properties['lastname'] ?? ''))),
                'email' => (string) ($properties['email'] ?? ''),
            ];
        }

        return $contacts;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function onlyId(array $results): ?string
    {
        $ids = [];

        foreach ($results as $result) {
            $id = (string) ($result['id'] ?? '');

            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        if (count($ids) !== 1) {
            return null;
        }

        return array_values($ids)[0];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function one(array $results, string $label): array
    {
        if (count($results) === 0) {
            throw new DeskException('No '.$label.' matched.');
        }

        if (count($results) > 1) {
            $ids = implode(', ', array_map(fn (array $result): string => (string) $result['id'], $results));

            throw new DeskException("More than one {$label} matched ({$ids}). Stop and choose one.");
        }

        return $results[0];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        $pending = Http::withToken($this->token())
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'reply-desk'])
            ->baseUrl($this->base());

        $response = match ($method) {
            'GET' => $pending->get($path),
            'POST' => $pending->post($path, $body ?? []),
            'PUT' => $pending->put($path, $body ?? []),
            default => throw new DeskException('Unsupported HubSpot request.'),
        };

        if ($response->status() === 204) {
            return [];
        }

        if (! $response->successful()) {
            throw new DeskException($this->failure($response));
        }

        return $response->json() ?? [];
    }

    private function failure(Response $response): string
    {
        $body = str_replace($this->token(), '[redacted]', $response->body());

        return 'HubSpot '.$response->status().': '.mb_substr($body, 0, 500);
    }

    private function token(): string
    {
        return (string) config('reply-desk.token');
    }

    private function base(): string
    {
        return rtrim((string) config('reply-desk.api_base'), '/');
    }
}
