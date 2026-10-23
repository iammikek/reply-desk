<?php

namespace App\Desk;

interface NoteStore
{
    public function assertPortal(): void;

    public function findCompany(string $name): Party;

    public function findContact(string $email): Party;

    public function writeNote(string $body, string $companyId, string $contactId): string;

    public function replaceNote(string $id, string $body): void;

    /**
     * @return list<StoredNote>
     */
    public function notes(): array;
}
