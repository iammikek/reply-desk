<?php

declare(strict_types=1);

namespace App\Desk\Queue;

use DateTimeImmutable;

final readonly class EarlierThread
{
    /**
     * @param  list<array{sent: DateTimeImmutable, subject: string, body: string}>  $replies
     */
    public function __construct(
        public DateTimeImmutable $receipt,
        public string $summary,
        public string $clientEmail,
        public array $replies,
    ) {}
}
