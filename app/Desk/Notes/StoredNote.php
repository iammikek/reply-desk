<?php

declare(strict_types=1);

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

    public function clientLabel(): string
    {
        return self::label($this->companyName, $this->contactName, $this->contactEmail);
    }

    public static function label(string $company, string $contactName, string $contactEmail = ''): string
    {
        $contact = $contactName !== '' ? $contactName : $contactEmail;

        return trim($company.' / '.$contact, ' /');
    }
}
