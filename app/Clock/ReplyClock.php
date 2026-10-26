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

    public function openMinutes(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $start = $start->setTimezone($this->zone);
        $end = $end->setTimezone($this->zone);

        if ($end < $start) {
            return 0;
        }

        $minutes = 0;
        $cursor = $start;

        while ($cursor < $end) {
            if ($this->insideWindow($cursor) && $cursor->format('H:i:s') < '20:00:00') {
                $close = $cursor->setTime(20, 0);
                $slice = $end < $close ? $end : $close;
                $minutes += (int) (($slice->getTimestamp() - $cursor->getTimestamp()) / 60);
                $cursor = $slice;

                continue;
            }

            $cursor = $this->nextWorkingMorning($cursor);

            if ($cursor > $end) {
                break;
            }
        }

        return $minutes;
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
