<?php

namespace App\Clock;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class ReplyClock
{
    public const string ZONE = 'Europe/London';

    private DateTimeZone $zone;

    public function __construct()
    {
        $this->zone = new DateTimeZone(self::ZONE);
    }

    public function schedule(DateTimeImmutable $receipt): ClockSchedule
    {
        $receipt = $receipt->setTimezone($this->zone);
        $clockStart = $this->insideWindow($receipt)
            ? $receipt
            : $this->nextWorkingMorning($receipt);

        return new ClockSchedule(
            $clockStart,
            $clockStart->add(new DateInterval('PT24H')),
        );
    }

    private function insideWindow(DateTimeImmutable $moment): bool
    {
        $day = (int) $moment->format('N');
        $time = $moment->format('H:i:s');

        return $day <= 5 && $time >= '08:00:00' && $time <= '20:00:00';
    }

    private function nextWorkingMorning(DateTimeImmutable $receipt): DateTimeImmutable
    {
        $morning = $receipt->setTime(8, 0);

        if ((int) $receipt->format('N') <= 5 && $receipt->format('H:i:s') < '08:00:00') {
            return $morning;
        }

        $morning = $morning->modify('+1 day');

        while ((int) $morning->format('N') >= 6) {
            $morning = $morning->modify('+1 day');
        }

        return $morning;
    }
}
