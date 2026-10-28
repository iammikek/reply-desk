<?php

declare(strict_types=1);

namespace App\Clock;

use DateTimeImmutable;

final readonly class ClockSchedule
{
    public function __construct(
        public DateTimeImmutable $clockStart,
        public DateTimeImmutable $replyDue,
    ) {}
}
