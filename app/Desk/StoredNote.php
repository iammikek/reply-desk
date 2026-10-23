<?php

namespace App\Desk;

final readonly class StoredNote
{
    public function __construct(
        public string $id,
        public string $body,
        public string $companyId,
        public string $companyName,
        public string $contactId,
        public string $contactName,
        public string $contactEmail,
    ) {}
}
