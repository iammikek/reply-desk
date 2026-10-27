<?php

namespace App\Clock;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class ReplyClock
{
    public const string ZONE = 'Europe/London';

    private PracticeHours $hours;

    private DateTimeZone $zone;

    public function __construct(?PracticeHours $hours = null)
    {
        $this->hours = $hours ?? PracticeHours::fromConfig(config('reply-desk'));
        $this->zone = $this->hours->zone();
    }

    public function timezone(): string
    {
        return $this->hours->timezone();
    }

    public static function zoneName(): string
    {
        return app(self::class)->timezone();
    }

    public function hours(): PracticeHours
    {
        return $this->hours;
    }

    public function schedule(DateTimeImmutable $receipt): ClockSchedule
    {
        $receipt = $receipt->setTimezone($this->zone);
        $clockStart = $this->hours->contains($receipt)
            ? $receipt
            : $this->hours->nextOpen($receipt);

        return new ClockSchedule(
            $clockStart,
            $clockStart->add(new DateInterval('PT'.$this->hours->intervalHours().'H')),
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
            $range = $this->hours->rangeContaining($cursor);

            if ($range !== null) {
                [$hour, $minute] = array_map('intval', explode(':', $range->close));
                $close = $cursor->setTime($hour, $minute);

                if ($cursor->format('H:i:s') >= $range->close.':00') {
                    $cursor = $this->hours->nextOpen($cursor);

                    if ($cursor > $end) {
                        break;
                    }

                    continue;
                }

                $slice = $end < $close ? $end : $close;
                $minutes += (int) (($slice->getTimestamp() - $cursor->getTimestamp()) / 60);
                $cursor = $slice;

                continue;
            }

            $cursor = $this->hours->nextOpen($cursor);

            if ($cursor > $end) {
                break;
            }
        }

        return $minutes;
    }
}
