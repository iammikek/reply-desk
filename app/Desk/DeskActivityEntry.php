<?php

declare(strict_types=1);

namespace App\Desk;

final readonly class DeskActivityEntry
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        public string $action,
        public string $summary,
        public ?string $inboundId = null,
        public ?string $noteId = null,
        public ?string $company = null,
        public ?string $contact = null,
        public ?string $companyId = null,
        public ?string $contactId = null,
        public ?array $context = null,
    ) {}
}
