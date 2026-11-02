<?php

namespace App\Desk\Notes;

use Closure;
use Illuminate\Support\Facades\Cache;

final class HubSpotNoteReader
{
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
     * One read of the queue per request.
     *
     * @var list<StoredNote>|null
     */
    private ?array $cachedNotes = null;

    private const int CACHE_SECONDS = 60;

    public function __construct(
        private HubSpotClient $client,
        private HubSpotCrmLookup $crm,
        private HubSpotFiles $files,
        private HubSpotPendingNotes $pending,
    ) {}

    /**
     * @return list<StoredNote>
     */
    public function all(Closure $assertPortal): array
    {
        if ($this->cachedNotes !== null) {
            return $this->cachedNotes;
        }

        $cached = $this->cachedList();

        if ($cached !== null) {
            $this->cachedNotes = $this->pending->merge($cached, []);

            return $this->cachedNotes;
        }

        $assertPortal();

        $raw = $this->searchClockNotes();
        $companies = [];
        $contacts = [];

        foreach ($raw as $note) {
            $companyId = $this->crm->onlyId($note['associations']['companies']['results'] ?? []);
            $contactId = $this->crm->onlyId($note['associations']['contacts']['results'] ?? []);

            if ($companyId === null || $contactId === null) {
                continue;
            }

            $companies[$companyId] = $companyId;
            $contacts[$contactId] = $contactId;
        }

        $companyNames = $this->crm->names('companies', array_values($companies), 'name');
        $contactRecords = $this->crm->contacts(array_values($contacts));
        $stored = [];
        $searchBodies = [];

        foreach ($raw as $note) {
            $companyId = $this->crm->onlyId($note['associations']['companies']['results'] ?? []);
            $contactId = $this->crm->onlyId($note['associations']['contacts']['results'] ?? []);
            $noteId = (string) ($note['id'] ?? '');

            if ($companyId === null || $contactId === null || $noteId === '') {
                continue;
            }

            $searchBodies[$noteId] = (string) ($note['properties']['hs_note_body'] ?? '');
            $contact = $contactRecords[$contactId] ?? ['name' => '', 'email' => ''];
            $attachmentId = $this->files->firstId((string) ($note['properties']['hs_attachment_ids'] ?? ''));

            $stored[] = new StoredNote(
                $noteId,
                $this->bodies[$noteId] ?? $searchBodies[$noteId],
                $companyId,
                $companyNames[$companyId] ?? '',
                $contactId,
                $contact['name'],
                $contact['email'],
                $attachmentId === null ? null : $this->files->name($attachmentId),
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

        $stored = $this->pending->merge($stored, $searchBodies);
        $this->remember($stored);

        return $stored;
    }

    public function recordWritten(StoredNote $note): void
    {
        $this->written[$note->id] = $note;
        $this->bodies[$note->id] = $note->body;
        $this->rememberLocal($note);
        Cache::forget($this->key());
        $this->pending->keep($note);
    }

    public function recordReplacement(StoredNote $note): void
    {
        $this->bodies[$note->id] = $note->body;

        if (isset($this->written[$note->id])) {
            $this->written[$note->id] = $note;
        }

        $this->rememberLocal($note);
        Cache::forget($this->key());
        $this->pending->keep($note);
    }

    public function known(string $id): ?StoredNote
    {
        if (isset($this->written[$id])) {
            return $this->written[$id];
        }

        foreach ($this->cachedNotes ?? $this->cachedList() ?? [] as $note) {
            if ($note->id === $id) {
                return $note;
            }
        }

        return $this->pending->find($id);
    }

    /**
     * @param  list<StoredNote>  $notes
     */
    private function remember(array $notes): void
    {
        $this->cachedNotes = $notes;
        Cache::put($this->key(), array_map(fn (StoredNote $note): array => [
            'id' => $note->id,
            'body' => $note->body,
            'companyId' => $note->companyId,
            'companyName' => $note->companyName,
            'contactId' => $note->contactId,
            'contactName' => $note->contactName,
            'contactEmail' => $note->contactEmail,
            'attachmentName' => $note->attachmentName,
            'attachmentId' => $note->attachmentId,
        ], $notes), self::CACHE_SECONDS);
    }

    /**
     * The cache stores fields only. Laravel does not restore cached objects.
     *
     * @return list<StoredNote>|null
     */
    private function cachedList(): ?array
    {
        $cached = Cache::get($this->key());

        if (! is_array($cached)) {
            if ($cached !== null) {
                Cache::forget($this->key());
            }

            return null;
        }

        $notes = [];

        foreach ($cached as $row) {
            $note = is_array($row) ? StoredNote::tryFromRow($row) : null;

            if (! $note instanceof StoredNote) {
                Cache::forget($this->key());

                return null;
            }

            $notes[] = $note;
        }

        return $notes;
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

            $found = $this->client->send('POST', '/crm/objects/2026-03/notes/search', $body);
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

            array_push($notes, ...$this->crm->withAssociations($pageNotes));
            $after = $found['paging']['next']['after'] ?? null;

            if ($after === null) {
                break;
            }
        }

        return $notes;
    }

    private function key(): string
    {
        return 'reply-desk.notes.'.(config('reply-desk.portal_id'));
    }
}
