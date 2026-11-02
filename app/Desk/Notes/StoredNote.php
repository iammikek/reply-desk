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

    /**
     * @param  array<mixed>  $row
     */
    public static function tryFromRow(array $row): ?self
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

        return new self(
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
}
