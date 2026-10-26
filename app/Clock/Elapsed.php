<?php

namespace App\Clock;

use DateTimeImmutable;
use DateTimeZone;

final class Elapsed
{
    public static function between(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $zone = new DateTimeZone(ReplyClock::ZONE);
        $start = $start->setTimezone($zone);
        $end = $end->setTimezone($zone);

        if ($end < $start) {
            return '';
        }

        $diff = $start->diff($end);
        $parts = [];
        $days = (int) $diff->days;

        if ($days === 1) {
            $parts[] = '1 day';
        } elseif ($days > 1) {
            $parts[] = $days.' days';
        }

        if ($diff->h === 1) {
            $parts[] = '1 hour';
        } elseif ($diff->h > 1) {
            $parts[] = $diff->h.' hours';
        }

        if ($diff->i === 1) {
            $parts[] = '1 minute';
        } elseif ($diff->i > 1) {
            $parts[] = $diff->i.' minutes';
        }

        return $parts === [] ? 'under a minute' : implode(' ', $parts);
    }
}
