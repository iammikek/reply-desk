<?php

namespace App\Desk;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class HubSpotNoteStore implements NoteStore
{
    private bool $portalChecked = false;

    /**
     * Notes written in this process. HubSpot search can lag behind the write.
     *
     * @var array<string, StoredNote>
     */
    private array $written = [];

    /**
     * Bodies written or replaced in this process. Search can still return the old text.
     *
     * @var array<string, string>
     */
    private array $bodies = [];

    /**
     * One read of the queue per request. A second read in the same request was running past the PHP time limit.
     *
     * @var list<StoredNote>|null
     */
    private ?array $cachedNotes = null;

    /**
     * Shared across requests so opening the queue does not spend the ten-second HubSpot limit.
     */
    private const NOTE_CACHE_SECONDS = 60;

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

        $results = $this->companyResults('EQ', [$name]);

        if ($results === []) {
            $tokens = array_values(array_filter(
                preg_split('/\s+/', $name) ?: [],
                fn (string $token): bool => $token !== '',
            ));

            if ($tokens !== []) {
                $results = $this->companyResults('CONTAINS_TOKEN', $tokens);
            }
        }

        if ($results === []) {
            throw new DeskException("No company matched \"{$name}\".");
        }

        $match = $this->one($results, 'company');

        return new Party((string) $match['id'], (string) ($match['properties']['name'] ?? $name));
    }

    /**
     * @param  list<string>  $values
     * @return list<array<string, mixed>>
     */
    private function companyResults(string $operator, array $values): array
    {
        $found = $this->send('POST', '/crm/objects/2026-03/companies/search', [
            'filterGroups' => [[
                'filters' => array_map(fn (string $value): array => [
                    'propertyName' => 'name',
                    'operator' => $operator,
                    'value' => $value,
                ], $values),
            ]],
            'properties' => ['name'],
            'limit' => 10,
        ]);

        return $found['results'] ?? [];
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

        $stored = new StoredNote($id, $body, $companyId, '', $contactId, '', '');
        $this->written[$id] = $stored;
        $this->bodies[$id] = $body;

        if ($this->cachedNotes !== null) {
            $this->cachedNotes[] = $stored;
        }

        Cache::forget($this->cacheKey());

        return $id;
    }

    public function replaceNote(string $id, string $body): void
    {
        $this->assertPortal();

        $this->send('PATCH', '/crm/objects/2026-03/notes/'.$id, [
            'properties' => [
                'hs_note_body' => $body,
            ],
        ]);

        $this->bodies[$id] = $body;

        if (isset($this->written[$id])) {
            $note = $this->written[$id];
            $this->written[$id] = new StoredNote($note->id, $body, $note->companyId, $note->companyName, $note->contactId, $note->contactName, $note->contactEmail);
        }

        $this->replaceCachedBody($id, $body);
    }

    public function notes(): array
    {
        if ($this->cachedNotes !== null) {
            return $this->cachedNotes;
        }

        $cached = $this->cachedList();

        if ($cached !== null) {
            $this->cachedNotes = $cached;

            return $cached;
        }

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
                $this->bodies[(string) $note['id']] ?? (string) ($note['properties']['hs_note_body'] ?? ''),
                $companyId,
                $companyNames[$companyId] ?? '',
                $contactId,
                $contact['name'],
                $contact['email'],
            );
        }

        $seen = [];

        foreach ($stored as $note) {
            $seen[$note->id] = true;
        }

        foreach ($this->written as $id => $note) {
            if (! isset($seen[$id])) {
                $stored[] = $note;
            }
        }

        $this->remember($stored);

        return $stored;
    }

    /**
     * @param  list<StoredNote>  $notes
     */
    private function remember(array $notes): void
    {
        $this->cachedNotes = $notes;
        Cache::put($this->cacheKey(), array_map(fn (StoredNote $note): array => [
            'id' => $note->id,
            'body' => $note->body,
            'companyId' => $note->companyId,
            'companyName' => $note->companyName,
            'contactId' => $note->contactId,
            'contactName' => $note->contactName,
            'contactEmail' => $note->contactEmail,
        ], $notes), self::NOTE_CACHE_SECONDS);
    }

    /**
     * The cache stores fields only. Laravel does not restore cached objects.
     *
     * @return list<StoredNote>|null
     */
    private function cachedList(): ?array
    {
        $cached = Cache::get($this->cacheKey());

        if (! is_array($cached)) {
            if ($cached !== null) {
                Cache::forget($this->cacheKey());
            }

            return null;
        }

        $notes = [];

        foreach ($cached as $row) {
            $note = is_array($row) ? $this->noteFromRow($row) : null;

            if ($note === null) {
                Cache::forget($this->cacheKey());

                return null;
            }

            $notes[] = $note;
        }

        return $notes;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function noteFromRow(array $row): ?StoredNote
    {
        foreach (['id', 'body', 'companyId', 'companyName', 'contactId', 'contactName', 'contactEmail'] as $key) {
            if (! is_string($row[$key] ?? null)) {
                return null;
            }
        }

        return new StoredNote($row['id'], $row['body'], $row['companyId'], $row['companyName'], $row['contactId'], $row['contactName'], $row['contactEmail']);
    }

    private function replaceCachedBody(string $id, string $body): void
    {
        $notes = $this->cachedNotes ?? $this->cachedList();

        if ($notes === null) {
            return;
        }

        foreach ($notes as $index => $note) {
            if ($note->id !== $id) {
                continue;
            }

            $notes[$index] = new StoredNote($note->id, $body, $note->companyId, $note->companyName, $note->contactId, $note->contactName, $note->contactEmail);
        }

        $this->remember($notes);
    }

    private function cacheKey(): string
    {
        return 'reply-desk.notes.'.((string) config('reply-desk.portal_id'));
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
            $pageNotes = [];

            foreach ($found['results'] ?? [] as $note) {
                $id = (string) ($note['id'] ?? '');

                if ($id !== '') {
                    $pageNotes[] = [
                        'id' => $id,
                        'properties' => [
                            'hs_note_body' => (string) ($note['properties']['hs_note_body'] ?? ''),
                        ],
                    ];
                }
            }

            array_push($notes, ...$this->withAssociations($pageNotes));

            $after = $found['paging']['next']['after'] ?? null;

            if ($after === null) {
                break;
            }
        }

        return $notes;
    }

    /**
     * Search already has the note text. Associations are two batch reads, not one GET per note.
     *
     * @param  list<array{id: string, properties: array{hs_note_body: string}}>  $notes
     * @return list<array<string, mixed>>
     */
    private function withAssociations(array $notes): array
    {
        if ($notes === []) {
            return [];
        }

        $ids = array_column($notes, 'id');
        $companies = $this->associated('companies', $ids);
        $contacts = $this->associated('contacts', $ids);

        foreach ($notes as $index => $note) {
            $notes[$index]['associations'] = [
                'companies' => ['results' => $this->associationResults($companies[$note['id']] ?? [])],
                'contacts' => ['results' => $this->associationResults($contacts[$note['id']] ?? [])],
            ];
        }

        return $notes;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    private function associated(string $object, array $ids): array
    {
        $linked = [];

        foreach (array_chunk($ids, 100) as $chunk) {
            $found = $this->send('POST', "/crm/associations/2026-03/notes/{$object}/batch/read", [
                'inputs' => array_map(fn (string $id): array => ['id' => $id], $chunk),
            ]);

            foreach ($found['results'] ?? [] as $row) {
                $from = (string) ($row['from']['id'] ?? '');

                if ($from === '') {
                    continue;
                }

                foreach ($row['to'] ?? [] as $to) {
                    $toId = (string) ($to['toObjectId'] ?? '');

                    if ($toId !== '') {
                        $linked[$from][$toId] = $toId;
                    }
                }
            }
        }

        return array_map(
            fn (array $ids): array => array_values($ids),
            $linked,
        );
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{id: string}>
     */
    private function associationResults(array $ids): array
    {
        return array_map(fn (string $id): array => ['id' => $id], $ids);
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
            ->connectTimeout(5)
            ->timeout(15)
            ->baseUrl($this->base());

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $response = match ($method) {
                'GET' => $pending->get($path),
                'POST' => $pending->post($path, $body ?? []),
                'PUT' => $pending->put($path, $body ?? []),
                'PATCH' => $pending->patch($path, $body ?? []),
                default => throw new DeskException('Unsupported HubSpot request.'),
            };

            if ($response->status() === 429 && $attempt < 3) {
                $this->pause($response);

                continue;
            }

            if ($response->status() === 204) {
                return [];
            }

            if (! $response->successful()) {
                throw new DeskException($this->failure($response));
            }

            return $response->json() ?? [];
        }

        throw new DeskException('HubSpot is rate limited.');
    }

    private function pause(Response $response): void
    {
        $header = $response->header('Retry-After');
        $wait = is_numeric($header) ? (int) $header : 1;

        if ($wait > 0) {
            sleep(min($wait, 10));
        }
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
