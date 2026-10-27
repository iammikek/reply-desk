<?php

namespace App\Clock;

use DateTimeImmutable;
use DateTimeZone;

final class Elapsed
{
    public static function between(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $zone = new DateTimeZone(ReplyClock::zoneName());
        $start = $start->setTimezone($zone);
        $end = $end->setTimezone($zone);

        if ($end < $start) {
            return '';
        }

        return self::phrase($start->diff($end));
    }

    public static function open(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $minutes = app(ReplyClock::class)->openMinutes($start, $end);

        if ($minutes < 1) {
            return $end < $start ? '' : 'under a minute';
        }

        $days = intdiv($minutes, 24 * 60);
        $hours = intdiv($minutes % (24 * 60), 60);
        $remainder = $minutes % 60;
        $parts = [];

        if ($days === 1) {
            $parts[] = '1 day';
        } elseif ($days > 1) {
            $parts[] = $days.' days';
        }

        if ($hours === 1) {
            $parts[] = '1 hour';
        } elseif ($hours > 1) {
            $parts[] = $hours.' hours';
        }

        if ($remainder === 1) {
            $parts[] = '1 minute';
        } elseif ($remainder > 1) {
            $parts[] = $remainder.' minutes';
        }

        return implode(' ', $parts);
    }

    private static function phrase(\DateInterval $diff): string
    {
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
