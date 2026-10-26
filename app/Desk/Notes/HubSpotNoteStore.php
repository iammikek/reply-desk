<?php

namespace App\Desk\Notes;

use App\Desk\DeskException;
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
     * File names looked up while reading notes. One note list should not ask HubSpot twice for the same file.
     *
     * @var array<string, string>
     */
    private array $fileNames = [];

    /**
     * Shared across requests so opening the queue does not spend the ten-second HubSpot limit.
     */
    private const NOTE_CACHE_SECONDS = 60;

    /**
     * HubSpot search can miss a note after it is written. Keep it here until a fresh search returns the same body.
     */
    private const PENDING_SECONDS = 900;

    /**
     * Companies and their contacts change rarely. The log form and the MCP read this list.
     */
    private const DIRECTORY_CACHE_SECONDS = 3600;

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

    public function createCompany(string $name): Party
    {
        $name = trim($name);

        if ($name === '') {
            throw new DeskException('A company is required.');
        }

        $this->assertPortal();
        $results = $this->companyResults('EQ', [$name]);

        if (count($results) > 1) {
            $this->one($results, 'company');
        }

        if ($results !== []) {
            throw new DeskException('That company is already there.');
        }

        $created = $this->send('POST', '/crm/objects/2026-03/companies', [
            'properties' => ['name' => $name],
        ]);
        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw new DeskException('HubSpot did not return a company id.');
        }

        Cache::forget($this->directoryCacheKey());

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
        $found = $this->send('POST', '/crm/objects/2026-03/contacts/search', [
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
            $this->one($results, 'contact');
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

        $created = $this->send('POST', '/crm/objects/2026-03/contacts', [
            'properties' => $properties,
        ]);
        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw new DeskException('HubSpot did not return a contact id.');
        }

        $this->send('PUT', "/crm/objects/2026-03/contact/{$id}/associations/default/company/{$companyId}");
        Cache::forget($this->directoryCacheKey());

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

        if ($file !== null) {
            $fileId = $this->upload($file);
            $properties['hs_attachment_ids'] = $fileId;
        }

        $note = $this->send('POST', '/crm/objects/2026-03/notes', [
            'properties' => $properties,
        ]);

        $id = (string) ($note['id'] ?? '');

        if ($id === '') {
            throw new DeskException('HubSpot did not return a note id.');
        }

        $this->send('PUT', "/crm/objects/2026-03/note/{$id}/associations/default/company/{$companyId}");
        $this->send('PUT', "/crm/objects/2026-03/note/{$id}/associations/default/contact/{$contactId}");

        $stored = $this->labelled($id, $body, $companyId, $contactId, $file?->name, $fileId);
        $this->written[$id] = $stored;
        $this->bodies[$id] = $body;
        $this->rememberLocal($stored);
        Cache::forget($this->cacheKey());
        $this->keepPending($stored);

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
        $current = $this->known($id);
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

        if (isset($this->written[$id])) {
            $this->written[$id] = $stored;
        }

        $this->rememberLocal($stored);
        Cache::forget($this->cacheKey());
        $this->keepPending($stored);
    }

    public function attachmentFor(string $noteId): ?NoteAttachment
    {
        foreach ($this->notes() as $note) {
            if ($note->id !== $noteId) {
                continue;
            }

            if ($note->attachmentId === null) {
                return null;
            }

            $signed = $this->send('GET', '/files/v3/files/'.rawurlencode($note->attachmentId).'/signed-url');
            $url = (string) ($signed['url'] ?? '');

            if ($url === '') {
                throw new DeskException('HubSpot did not return a file link.');
            }

            return new NoteAttachment($note->attachmentName ?? 'Attachment', url: $url);
        }

        return null;
    }

    public function notes(): array
    {
        if ($this->cachedNotes !== null) {
            return $this->cachedNotes;
        }

        $cached = $this->cachedList();

        if ($cached !== null) {
            $this->cachedNotes = $this->withPending($cached, []);

            return $this->cachedNotes;
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
        $searchBodies = [];

        foreach ($raw as $note) {
            $companyId = $this->onlyId($note['associations']['companies']['results'] ?? []);
            $contactId = $this->onlyId($note['associations']['contacts']['results'] ?? []);
            $noteId = (string) ($note['id'] ?? '');

            if ($companyId === null || $contactId === null || $noteId === '') {
                continue;
            }

            $searchBodies[$noteId] = (string) ($note['properties']['hs_note_body'] ?? '');
            $contact = $contactRecords[$contactId] ?? ['name' => '', 'email' => ''];

            $attachmentId = $this->firstFileId((string) ($note['properties']['hs_attachment_ids'] ?? ''));

            $stored[] = new StoredNote(
                $noteId,
                $this->bodies[$noteId] ?? $searchBodies[$noteId],
                $companyId,
                $companyNames[$companyId] ?? '',
                $contactId,
                $contact['name'],
                $contact['email'],
                $attachmentId === null ? null : $this->fileName($attachmentId),
                $attachmentId,
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

        $stored = $this->withPending($stored, $searchBodies);
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
            'attachmentName' => $note->attachmentName,
            'attachmentId' => $note->attachmentId,
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

        $attachmentName = $row['attachmentName'] ?? null;
        $attachmentId = $row['attachmentId'] ?? null;

        if (($attachmentName !== null && ! is_string($attachmentName)) || ($attachmentId !== null && ! is_string($attachmentId))) {
            return null;
        }

        return new StoredNote(
            $row['id'],
            $row['body'],
            $row['companyId'],
            $row['companyName'],
            $row['contactId'],
            $row['contactName'],
            $row['contactEmail'],
            $attachmentName !== '' ? $attachmentName : null,
            $attachmentId !== '' ? $attachmentId : null,
        );
    }

    private function rememberLocal(StoredNote $stored): void
    {
        if ($this->cachedNotes === null) {
            return;
        }

        foreach ($this->cachedNotes as $index => $note) {
            if ($note->id === $stored->id) {
                $this->cachedNotes[$index] = $stored;

                return;
            }
        }

        $this->cachedNotes[] = $stored;
    }

    private function known(string $id): ?StoredNote
    {
        if (isset($this->written[$id])) {
            return $this->written[$id];
        }

        foreach ($this->cachedNotes ?? $this->cachedList() ?? [] as $note) {
            if ($note->id === $id) {
                return $note;
            }
        }

        return $this->pendingNotes()[$id] ?? null;
    }

    private function labelled(string $id, string $body, string $companyId, string $contactId, ?string $attachmentName = null, ?string $attachmentId = null): StoredNote
    {
        $companyName = '';
        $contactName = '';
        $contactEmail = '';

        foreach ($this->directoryFromCache(Cache::get($this->directoryCacheKey())) ?? [] as $company) {
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
            $companyName = $this->names('companies', [$companyId], 'name')[$companyId] ?? '';
        }

        if ($contactEmail === '') {
            $person = $this->contacts([$contactId])[$contactId] ?? null;

            if ($person !== null) {
                $contactName = $person['name'];
                $contactEmail = $person['email'];
            }
        }

        return new StoredNote($id, $body, $companyId, $companyName, $contactId, $contactName, $contactEmail, $attachmentName, $attachmentId);
    }

    private function keepPending(StoredNote $note): void
    {
        Cache::lock($this->pendingLockKey(), 5)->block(5, function () use ($note): void {
            $pending = $this->pendingRows();
            $existing = $pending[$note->id] ?? null;
            $row = $this->pendingRow($note);

            if (is_array($existing)) {
                foreach (['companyId', 'companyName', 'contactId', 'contactName', 'contactEmail'] as $key) {
                    if ($row[$key] === '' && is_string($existing[$key] ?? null) && $existing[$key] !== '') {
                        $row[$key] = $existing[$key];
                    }
                }
            }

            $pending[$note->id] = $row;
            $this->storePending($pending);
        });
    }

    /**
     * @param  list<string>  $ids
     */
    private function dropPending(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Cache::lock($this->pendingLockKey(), 5)->block(5, function () use ($ids): void {
            $pending = $this->pendingRows();

            foreach ($ids as $id) {
                unset($pending[$id]);
            }

            $this->storePending($pending);
        });
    }

    /**
     * A one-minute snapshot can already contain a pending note. Only a fresh search may remove it.
     *
     * @param  list<StoredNote>  $notes
     * @param  array<string, string>  $searchBodies
     * @return list<StoredNote>
     */
    private function withPending(array $notes, array $searchBodies): array
    {
        $pending = $this->pendingNotes();

        if ($pending === []) {
            return $notes;
        }

        $byId = [];

        foreach ($notes as $note) {
            $byId[$note->id] = $note;
        }

        $drop = [];

        foreach ($pending as $id => $waiting) {
            if (! isset($byId[$id])) {
                $byId[$id] = $waiting;

                continue;
            }

            if (array_key_exists($id, $searchBodies) && $searchBodies[$id] === $waiting->body) {
                if ($waiting->attachmentId !== null && $byId[$id]->attachmentId === null) {
                    $byId[$id] = $this->withAttachment($byId[$id], $waiting);

                    continue;
                }

                $drop[] = $id;

                continue;
            }

            if ($byId[$id]->body === $waiting->body) {
                if ($byId[$id]->attachmentName === null && $waiting->attachmentName !== null) {
                    $byId[$id] = $this->withAttachment($byId[$id], $waiting);
                }

                continue;
            }

            $current = $byId[$id];
            $byId[$id] = new StoredNote(
                $current->id,
                $waiting->body,
                $current->companyId !== '' ? $current->companyId : $waiting->companyId,
                $current->companyName !== '' ? $current->companyName : $waiting->companyName,
                $current->contactId !== '' ? $current->contactId : $waiting->contactId,
                $current->contactName !== '' ? $current->contactName : $waiting->contactName,
                $current->contactEmail !== '' ? $current->contactEmail : $waiting->contactEmail,
                $waiting->attachmentName ?? $current->attachmentName,
                $waiting->attachmentId ?? $current->attachmentId,
            );
        }

        $this->dropPending($drop);

        return array_values($byId);
    }

    /**
     * @return array<string, StoredNote>
     */
    private function pendingNotes(): array
    {
        $notes = [];

        foreach ($this->pendingRows() as $id => $row) {
            $note = $this->noteFromRow($row);

            if ($note !== null) {
                $notes[$id] = $note;
            }
        }

        return $notes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pendingRows(): array
    {
        $cached = Cache::get($this->pendingKey());

        if (! is_array($cached)) {
            if ($cached !== null) {
                Cache::forget($this->pendingKey());
            }

            return [];
        }

        $now = now()->getTimestamp();
        $rows = [];

        foreach ($cached as $row) {
            if (! is_array($row) || $this->noteFromRow($row) === null) {
                continue;
            }

            $expires = $row['expiresAt'] ?? null;

            if (! is_int($expires) && ! (is_string($expires) && ctype_digit($expires))) {
                continue;
            }

            if ((int) $expires <= $now) {
                continue;
            }

            $rows[$row['id']] = $row;
        }

        return $rows;
    }

    /**
     * @return array{id: string, body: string, companyId: string, companyName: string, contactId: string, contactName: string, contactEmail: string, attachmentName: ?string, attachmentId: ?string, expiresAt: int}
     */
    private function pendingRow(StoredNote $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'companyId' => $note->companyId,
            'companyName' => $note->companyName,
            'contactId' => $note->contactId,
            'contactName' => $note->contactName,
            'contactEmail' => $note->contactEmail,
            'attachmentName' => $note->attachmentName,
            'attachmentId' => $note->attachmentId,
            'expiresAt' => now()->addSeconds(self::PENDING_SECONDS)->getTimestamp(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $pending
     */
    private function storePending(array $pending): void
    {
        if ($pending === []) {
            Cache::forget($this->pendingKey());

            return;
        }

        Cache::put($this->pendingKey(), array_values($pending), self::PENDING_SECONDS);
    }

    private function pendingKey(): string
    {
        return 'reply-desk.pending.'.((string) config('reply-desk.portal_id'));
    }

    private function pendingLockKey(): string
    {
        return $this->pendingKey().'.lock';
    }

    private function cacheKey(): string
    {
        return 'reply-desk.notes.'.((string) config('reply-desk.portal_id'));
    }

    public function directory(): array
    {
        $cached = $this->directoryFromCache(Cache::get($this->directoryCacheKey()));

        if ($cached !== null) {
            return $cached;
        }

        $this->assertPortal();

        $companies = $this->listCompanies();
        $links = $this->associated('companies', 'contacts', array_column($companies, 'id'));
        $contactIds = [];

        foreach ($links as $ids) {
            foreach ($ids as $id) {
                $contactIds[$id] = $id;
            }
        }

        $people = [];

        foreach (array_chunk(array_values($contactIds), 100) as $chunk) {
            $people += $this->contacts($chunk);
        }

        $directory = [];

        foreach ($companies as $company) {
            $contacts = [];

            foreach ($links[$company['id']] ?? [] as $contactId) {
                $person = $people[$contactId] ?? null;

                if ($person === null || $person['email'] === '') {
                    continue;
                }

                $contacts[] = [
                    'id' => $contactId,
                    'name' => $person['name'],
                    'email' => $person['email'],
                ];
            }

            usort($contacts, fn (array $left, array $right): int => strcasecmp($left['name'].$left['email'], $right['name'].$right['email']));

            $directory[] = [
                'id' => $company['id'],
                'name' => $company['name'],
                'contacts' => $contacts,
            ];
        }

        usort($directory, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
        Cache::put($this->directoryCacheKey(), $directory, self::DIRECTORY_CACHE_SECONDS);

        return $directory;
    }

    /**
     * @return list<array{id: string, name: string, contacts: list<array{id: string, name: string, email: string}>}>|null
     */
    private function directoryFromCache(mixed $cached): ?array
    {
        if (! is_array($cached)) {
            if ($cached !== null) {
                Cache::forget($this->directoryCacheKey());
            }

            return null;
        }

        foreach ($cached as $company) {
            if (! is_array($company) || ! is_string($company['id'] ?? null) || ! is_string($company['name'] ?? null) || ! is_array($company['contacts'] ?? null)) {
                Cache::forget($this->directoryCacheKey());

                return null;
            }

            foreach ($company['contacts'] as $contact) {
                if (! is_array($contact) || ! is_string($contact['id'] ?? null) || ! is_string($contact['name'] ?? null) || ! is_string($contact['email'] ?? null)) {
                    Cache::forget($this->directoryCacheKey());

                    return null;
                }
            }
        }

        return $cached;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function listCompanies(): array
    {
        $companies = [];
        $after = null;

        for ($page = 0; $page < 10; $page++) {
            $query = ['limit' => 100, 'properties' => 'name'];

            if ($after !== null) {
                $query['after'] = $after;
            }

            $found = $this->send('GET', '/crm/objects/2026-03/companies', null, $query);

            foreach ($found['results'] ?? [] as $row) {
                $id = (string) ($row['id'] ?? '');
                $name = trim((string) ($row['properties']['name'] ?? ''));

                if ($id !== '' && $name !== '') {
                    $companies[$id] = ['id' => $id, 'name' => $name];
                }
            }

            $after = $found['paging']['next']['after'] ?? null;

            if (! is_string($after) || $after === '') {
                break;
            }
        }

        return array_values($companies);
    }

    private function directoryCacheKey(): string
    {
        return 'reply-desk.directory.'.((string) config('reply-desk.portal_id'));
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
                'properties' => ['hs_note_body', 'hs_attachment_ids'],
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
                            'hs_attachment_ids' => (string) ($note['properties']['hs_attachment_ids'] ?? ''),
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
     * @param  list<array{id: string, properties: array{hs_note_body: string, hs_attachment_ids: string}}>  $notes
     * @return list<array<string, mixed>>
     */
    private function withAssociations(array $notes): array
    {
        if ($notes === []) {
            return [];
        }

        $ids = array_column($notes, 'id');
        $companies = $this->associated('notes', 'companies', $ids);
        $contacts = $this->associated('notes', 'contacts', $ids);

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
    private function associated(string $fromObject, string $object, array $ids): array
    {
        $linked = [];

        foreach (array_chunk($ids, 100) as $chunk) {
            $found = $this->send('POST', "/crm/associations/2026-03/{$fromObject}/{$object}/batch/read", [
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
    /**
     * @param  array<string, mixed>  $query
     */
    private function send(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $pending = Http::withToken($this->token())
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'reply-desk'])
            ->connectTimeout(5)
            ->timeout(15)
            ->baseUrl($this->base());

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $response = match ($method) {
                'GET' => $pending->get($path, $query),
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

    private function withAttachment(StoredNote $note, StoredNote $attachment): StoredNote
    {
        return new StoredNote(
            $note->id,
            $note->body,
            $note->companyId,
            $note->companyName,
            $note->contactId,
            $note->contactName,
            $note->contactEmail,
            $attachment->attachmentName,
            $attachment->attachmentId,
        );
    }

    private function firstFileId(string $ids): ?string
    {
        $id = trim(explode(';', $ids)[0]);

        if ($id === '' || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            return null;
        }

        return $id;
    }

    private function fileName(string $id): string
    {
        if (isset($this->fileNames[$id])) {
            return $this->fileNames[$id];
        }

        try {
            $file = $this->send('GET', '/files/v3/files/'.rawurlencode($id));
        } catch (DeskException) {
            return $this->fileNames[$id] = 'Attachment';
        }

        $name = trim((string) ($file['name'] ?? ''));
        $extension = trim((string) ($file['extension'] ?? ''), '. ');

        if ($name === '') {
            return $this->fileNames[$id] = 'Attachment';
        }

        if ($extension !== '' && ! str_ends_with(strtolower($name), '.'.strtolower($extension))) {
            $name .= '.'.$extension;
        }

        return $this->fileNames[$id] = $name;
    }

    private function upload(NoteFile $file): string
    {
        if (! is_file($file->path)) {
            throw new DeskException('That attachment could not be read.');
        }

        $handle = fopen($file->path, 'r');

        if ($handle === false) {
            throw new DeskException('That attachment could not be read.');
        }

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'reply-desk'])
            ->connectTimeout(5)
            ->timeout(30)
            ->attach('file', $handle, $file->name)
            ->post($this->base().'/files/v3/files', [
                'folderPath' => '/reply-desk',
                'options' => json_encode(['access' => 'PRIVATE']),
            ]);

        if (! $response->successful()) {
            throw new DeskException($this->failure($response));
        }

        $id = (string) ($response->json('id') ?? '');

        if ($id === '') {
            throw new DeskException('HubSpot did not return a file id.');
        }

        return $id;
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
