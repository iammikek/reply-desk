<?php

namespace App\Desk\Notes;

use Illuminate\Support\Facades\Cache;

final class HubSpotPendingNotes
{
    /**
     * HubSpot search can miss a note after it is written.
     */
    private const int PENDING_SECONDS = 900;

    public function keep(StoredNote $note): void
    {
        Cache::lock($this->lockKey(), 5)->block(5, function () use ($note): void {
            $pending = $this->rows();
            $existing = $pending[$note->id] ?? null;
            $row = $this->row($note);

            if (is_array($existing)) {
                foreach (['companyId', 'companyName', 'contactId', 'contactName', 'contactEmail'] as $key) {
                    if ($row[$key] === '' && is_string($existing[$key] ?? null) && $existing[$key] !== '') {
                        $row[$key] = $existing[$key];
                    }
                }
            }

            $pending[$note->id] = $row;
            $this->store($pending);
        });
    }

    public function find(string $id): ?StoredNote
    {
        return $this->notes()[$id] ?? null;
    }

    /**
     * A one-minute snapshot can already contain a pending note. Only a fresh search may remove it.
     *
     * @param  list<StoredNote>  $notes
     * @param  array<string, string>  $searchBodies
     * @return list<StoredNote>
     */
    public function merge(array $notes, array $searchBodies): array
    {
        $pending = $this->notes();

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

        $this->drop($drop);

        return array_values($byId);
    }

    /**
     * @return array<string, StoredNote>
     */
    private function notes(): array
    {
        $notes = [];

        foreach ($this->rows() as $id => $row) {
            $note = StoredNote::tryFromRow($row);

            if ($note instanceof StoredNote) {
                $notes[$id] = $note;
            }
        }

        return $notes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $cached = Cache::get($this->key());

        if (! is_array($cached)) {
            if ($cached !== null) {
                Cache::forget($this->key());
            }

            return [];
        }

        $now = now()->getTimestamp();
        $rows = [];

        foreach ($cached as $row) {
            if (! is_array($row) || ! StoredNote::tryFromRow($row) instanceof StoredNote) {
                continue;
            }

            $expires = $row['expiresAt'] ?? null;

            if (! is_int($expires) && (! is_string($expires) || ! ctype_digit($expires))) {
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
    private function row(StoredNote $note): array
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
     * @param  list<string>  $ids
     */
    private function drop(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Cache::lock($this->lockKey(), 5)->block(5, function () use ($ids): void {
            $pending = $this->rows();

            foreach ($ids as $id) {
                unset($pending[$id]);
            }

            $this->store($pending);
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $pending
     */
    private function store(array $pending): void
    {
        if ($pending === []) {
            Cache::forget($this->key());

            return;
        }

        Cache::put($this->key(), array_values($pending), self::PENDING_SECONDS);
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

    private function key(): string
    {
        return 'reply-desk.pending.'.(config('reply-desk.portal_id'));
    }

    private function lockKey(): string
    {
        return $this->key().'.lock';
    }
}
