<?php

namespace App\Desk\Backfill;

use App\Clock\ReceiptSource;
use DateTimeImmutable;

final readonly class SentTableRow
{
    public function __construct(
        public string $company,
        public string $email,
        public DateTimeImmutable $receipt,
        public ReceiptSource $receiptSource,
        public string $summary,
        public DateTimeImmutable $sentAt,
        public string $subject,
        public ?string $clientEmailPath = null,
        public ?string $replyPath = null,
    ) {}
}
