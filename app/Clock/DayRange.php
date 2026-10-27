<?php

namespace App\Clock;

final readonly class DayRange
{
    public function __construct(
        public string $open,
        public string $close,
    ) {}

    public function contains(string $time): bool
    {
        return $time >= $this->open.':00' && $time <= $this->close.':00';
    }

    public function openBeforeClose(): bool
    {
        return $this->open < $this->close;
    }

    public function overlaps(self $other): bool
    {
        return $this->open.':00' <= $other->close.':00'
            && $other->open.':00' <= $this->close.':00';
    }
}
