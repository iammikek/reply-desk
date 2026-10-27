<?php

namespace App\Desk;

use App\Clock\ReplyClock;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class LondonTime
{
    public static function parse(mixed $value): DateTimeImmutable
    {
        $zoneName = ReplyClock::zoneName();
        $zone = new DateTimeZone($zoneName);

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($zone);
        }

        $text = trim((string) $value);

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $text, $zone);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new DeskException('A time must be Y-m-d H:i in '.$zoneName.'.');
    }
}
