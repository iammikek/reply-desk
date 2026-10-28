<?php

declare(strict_types=1);

namespace App\Clock;

use DateTimeImmutable;

final readonly class ParsedInbound
{
    public function __construct(
        public DateTimeImmutable $receipt,
        public ReceiptSource $receiptSource,
        public string $summary,
    ) {}
}
