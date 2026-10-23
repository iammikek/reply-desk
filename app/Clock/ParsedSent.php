<?php

namespace App\Clock;

use DateTimeImmutable;

final readonly class ParsedSent
{
    public function __construct(
        public string $closes,
        public DateTimeImmutable $sent,
        public string $subject,
    ) {}
}
