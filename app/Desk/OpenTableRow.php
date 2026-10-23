<?php

namespace App\Desk;

use App\Clock\ReceiptSource;
use DateTimeImmutable;

final readonly class OpenTableRow
{
    public function __construct(
        public string $company,
        public string $email,
        public DateTimeImmutable $receipt,
        public ReceiptSource $receiptSource,
        public string $summary,
        public ?string $clientEmailPath = null,
        public ?string $replyPath = null,
    ) {}
}
