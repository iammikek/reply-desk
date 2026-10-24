<?php

namespace App\Desk;

interface NoteStore
{
    public function assertPortal(): void;

    public function findCompany(string $name): Party;

    public function findContact(string $email): Party;

    public function writeNote(string $body, string $companyId, string $contactId, ?NoteFile $file = null): string;

    public function replaceNote(string $id, string $body): void;

    /**
     * @return list<StoredNote>
     */
    public function notes(): array;

    /**
     * Companies already on the store, with the contacts on each company.
     *
     * @return list<array{id: string, name: string, contacts: list<array{id: string, name: string, email: string}>}>
     */
    public function directory(): array;
}
