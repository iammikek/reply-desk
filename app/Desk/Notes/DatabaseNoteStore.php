<?php

namespace App\Desk\Notes;

use App\Desk\DeskException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DatabaseNoteStore implements NoteStore
{
    public function assertPortal(): void
    {
        // The memory store is the showable copy. It has no HubSpot portal.
    }

    public function findCompany(string $name): Party
    {
        return $this->findParty('company', 'name', trim($name), 'company');
    }

    public function findContact(string $email): Party
    {
        return $this->findParty('contact', 'email', strtolower(trim($email)), 'contact');
    }

    public function writeNote(string $body, string $companyId, string $contactId, ?NoteFile $file = null): string
    {
        $company = $this->party('company', $companyId);
        $contact = $this->party('contact', $contactId);
        $highest = DB::table('fake_notes')->pluck('id')->map(fn (mixed $existing): int => (int) $existing)->max();
        $id = (string) (($highest ?? 0) + 1);

        if ($file !== null) {
            $this->keepFile($id, $file);
        }

        DB::table('fake_notes')->insert([
            'id' => $id,
            'body' => $body,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'contact_id' => $contact->id,
            'contact_name' => $contact->name,
            'contact_email' => $contact->email,
            'attachment_name' => $file?->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function replaceNote(string $id, string $body): void
    {
        $updated = DB::table('fake_notes')->where('id', $id)->update([
            'body' => $body,
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            throw new DeskException("No note {$id}.");
        }
    }

    public function attachmentFor(string $noteId): ?NoteAttachment
    {
        $note = collect($this->notes())->first(fn (StoredNote $stored): bool => $stored->id === $noteId);

        if (! $note instanceof StoredNote || $note->attachmentName === null) {
            return null;
        }

        $name = basename($note->attachmentName);

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $path = 'note-files/'.$note->id.'/'.$name;

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return new NoteAttachment($name, $path);
    }

    public function notes(): array
    {
        return DB::table('fake_notes')
            ->orderBy('id')
            ->get()
            ->map(function (object $row): StoredNote {
                $name = $row->attachment_name ?? null;

                return new StoredNote(
                    (string) $row->id,
                    $row->body,
                    $row->company_id,
                    $row->company_name,
                    $row->contact_id,
                    $row->contact_name,
                    $row->contact_email,
                    is_string($name) && $name !== '' ? $name : null,
                );
            })
            ->all();
    }

    public function directory(): array
    {
        $contacts = [];

        foreach (DB::table('fake_parties')->where('kind', 'contact')->orderBy('name')->get() as $row) {
            $companyId = (string) ($row->company_id ?? '');
            $email = (string) $row->email;

            if ($companyId === '' || $email === '') {
                continue;
            }

            $contacts[$companyId][] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'email' => $email,
            ];
        }

        $directory = [];

        foreach (DB::table('fake_parties')->where('kind', 'company')->orderBy('name')->get() as $row) {
            $id = (string) $row->id;
            $directory[] = [
                'id' => $id,
                'name' => (string) $row->name,
                'contacts' => $contacts[$id] ?? [],
            ];
        }

        return $directory;
    }

    private function findParty(string $kind, string $column, string $value, string $label): Party
    {
        if ($value === '') {
            throw new DeskException('A '.$label.' is required.');
        }

        $rows = DB::table('fake_parties')
            ->where('kind', $kind)
            ->where($column, $value)
            ->get();

        if ($rows->count() === 0) {
            throw new DeskException('No '.$label.' matched.');
        }

        if ($rows->count() > 1) {
            $ids = $rows->pluck('id')->implode(', ');

            throw new DeskException("More than one {$label} matched ({$ids}). Stop and choose one.");
        }

        $row = $rows->first();

        return new Party((string) $row->id, $row->name, (string) $row->email);
    }

    private function party(string $kind, string $id): Party
    {
        $row = DB::table('fake_parties')->where('kind', $kind)->where('id', $id)->first();

        if ($row === null) {
            throw new DeskException('No '.$kind.' matched.');
        }

        return new Party((string) $row->id, $row->name, (string) $row->email);
    }

    private function keepFile(string $id, NoteFile $file): void
    {
        if (! is_file($file->path)) {
            throw new DeskException('That attachment could not be read.');
        }

        $stored = Storage::disk('local')->put('note-files/'.$id.'/'.$file->name, fopen($file->path, 'r'));

        if ($stored === false) {
            throw new DeskException('That attachment could not be stored.');
        }
    }
}
