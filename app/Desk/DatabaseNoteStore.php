<?php

namespace App\Desk;

use Illuminate\Support\Facades\DB;

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

    public function writeNote(string $body, string $companyId, string $contactId): string
    {
        $company = $this->party('company', $companyId);
        $contact = $this->party('contact', $contactId);
        $highest = DB::table('fake_notes')->pluck('id')->map(fn (mixed $existing): int => (int) $existing)->max();
        $id = (string) (($highest ?? 0) + 1);

        DB::table('fake_notes')->insert([
            'id' => $id,
            'body' => $body,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'contact_id' => $contact->id,
            'contact_name' => $contact->name,
            'contact_email' => $contact->email,
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

    public function notes(): array
    {
        return DB::table('fake_notes')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): StoredNote => new StoredNote(
                (string) $row->id,
                $row->body,
                $row->company_id,
                $row->company_name,
                $row->contact_id,
                $row->contact_name,
                $row->contact_email,
            ))
            ->all();
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
}
