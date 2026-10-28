<?php

namespace App\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class PracticeHours
{
    /** @var list<string> */
    public const array DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @param  array<string, list<DayRange>>  $ranges
     */
    private function __construct(
        private readonly string $timezone,
        private readonly int $intervalHours,
        private array $ranges,
    ) {}

    /**
     * @param  array{
     *     timezone?: mixed,
     *     interval_hours?: mixed,
     *     hours?: mixed,
     * }  $config
     */
    public static function fromConfig(array $config): self
    {
        $timezone = is_string($config['timezone'] ?? null) ? $config['timezone'] : '';

        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            throw new PracticeHoursException('That timezone is not known.');
        }

        $interval = (int) ($config['interval_hours'] ?? 0);

        if ($interval < 1) {
            throw new PracticeHoursException('The reply interval must be at least one hour.');
        }

        $hours = $config['hours'] ?? null;

        if (! is_array($hours)) {
            throw new PracticeHoursException('Practice hours must list ranges for each day.');
        }

        /** @var array<string, list<DayRange>> $ranges */
        $ranges = [];
        $any = false;

        foreach (self::DAYS as $day) {
            if (! array_key_exists($day, $hours)) {
                throw new PracticeHoursException('Practice hours must list ranges for each day.');
            }

            $dayRanges = $hours[$day];

            if (! is_array($dayRanges)) {
                throw new PracticeHoursException('Each day must be a list of open ranges.');
            }

            $parsed = [];

            foreach ($dayRanges as $range) {
                if (! is_array($range) || count($range) !== 2 || ! is_string($range[0] ?? null) || ! is_string($range[1] ?? null)) {
                    throw new PracticeHoursException('Each range must be an open time and a close time.');
                }

                if (! self::validTime($range[0]) || ! self::validTime($range[1])) {
                    throw new PracticeHoursException('Each open and close must be H:i.');
                }

                $dayRange = new DayRange($range[0], $range[1]);

                if (! $dayRange->openBeforeClose()) {
                    throw new PracticeHoursException('A range close must be after its open.');
                }

                foreach ($parsed as $existing) {
                    if ($dayRange->overlaps($existing)) {
                        throw new PracticeHoursException('Ranges on the same day must not overlap.');
                    }
                }

                $parsed[] = $dayRange;
                $any = true;
            }

            usort($parsed, fn (DayRange $left, DayRange $right): int => $left->open <=> $right->open);
            $ranges[$day] = $parsed;
        }

        foreach (array_keys($hours) as $day) {
            if (! in_array($day, self::DAYS, true)) {
                throw new PracticeHoursException('That day is not known.');
            }
        }

        if (! $any) {
            throw new PracticeHoursException('At least one open range is required.');
        }

        return new self($timezone, $interval, $ranges);
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function intervalHours(): int
    {
        return $this->intervalHours;
    }

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    public function contains(DateTimeImmutable $moment): bool
    {
        return $this->rangeContaining($moment) instanceof DayRange;
    }

    public function rangeContaining(DateTimeImmutable $moment): ?DayRange
    {
        $moment = $moment->setTimezone($this->zone());
        $time = $moment->format('H:i:s');

        foreach ($this->rangesFor($moment) as $range) {
            if ($range->contains($time)) {
                return $range;
            }
        }

        return null;
    }

    public function nextOpen(DateTimeImmutable $moment): DateTimeImmutable
    {
        $moment = $moment->setTimezone($this->zone());

        for ($offset = 0; $offset < 8; $offset++) {
            $day = $moment->modify('+'.$offset.' day')->setTime(0, 0);

            foreach ($this->rangesFor($day) as $range) {
                [$hour, $minute] = array_map(intval(...), explode(':', $range->open));
                $open = $day->setTime($hour, $minute);

                if ($open > $moment) {
                    return $open;
                }
            }
        }

        throw new PracticeHoursException('No later open was found.');
    }

    /**
     * @return list<DayRange>
     */
    private function rangesFor(DateTimeImmutable $moment): array
    {
        return $this->ranges[strtolower($moment->format('l'))] ?? [];
    }

    private static function validTime(string $value): bool
    {
        if (preg_match('/^\d{2}:\d{2}$/', $value) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!H:i', $value);

        return $parsed !== false && $parsed->format('H:i') === $value;
    }
}
