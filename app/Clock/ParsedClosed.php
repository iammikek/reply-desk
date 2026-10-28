<?php

declare(strict_types=1);

namespace App\Clock;

use DateTimeImmutable;

final readonly class ParsedClosed
{
    public function __construct(
        public string $closes,
        public DateTimeImmutable $closed,
    ) {}
}
