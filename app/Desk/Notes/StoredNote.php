<?php

namespace App\Desk\Notes;

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
        public ?string $attachmentName = null,
        public ?string $attachmentId = null,
    ) {}
}
