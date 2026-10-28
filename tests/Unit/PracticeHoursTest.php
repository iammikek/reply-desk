<?php

use App\Clock\PracticeHours;
use App\Clock\PracticeHoursException;
use App\Clock\ReplyClock;

function practiceHoursConfig(array $overrides = []): array
{
    $config = [
        'timezone' => ReplyClock::ZONE,
        'interval_hours' => 24,
        'hours' => [
            'monday' => [['08:00', '20:00']],
            'tuesday' => [['08:00', '20:00']],
            'wednesday' => [['08:00', '20:00']],
            'thursday' => [['08:00', '20:00']],
            'friday' => [['08:00', '20:00']],
            'saturday' => [],
            'sunday' => [],
        ],
    ];

    if (isset($overrides['hours'])) {
        $config['hours'] = $overrides['hours'];
        unset($overrides['hours']);
    }

    return array_merge($config, $overrides);
}

it('refuses bad practice hours', function (array $overrides, string $message): void {
    expect(fn (): PracticeHours => PracticeHours::fromConfig(practiceHoursConfig($overrides)))
        ->toThrow(PracticeHoursException::class, $message);
})->with([
    'empty week' => [[
        'hours' => [
            'monday' => [],
            'tuesday' => [],
            'wednesday' => [],
            'thursday' => [],
            'friday' => [],
            'saturday' => [],
            'sunday' => [],
        ],
    ], 'At least one open range is required.'],
    'close before open' => [[
        'hours' => [
            'monday' => [['12:00', '08:00']],
            'tuesday' => [],
            'wednesday' => [],
            'thursday' => [],
            'friday' => [],
            'saturday' => [],
            'sunday' => [],
        ],
    ], 'A range close must be after its open.'],
    'overlap' => [[
        'hours' => [
            'monday' => [['08:00', '14:00'], ['12:00', '18:00']],
            'tuesday' => [],
            'wednesday' => [],
            'thursday' => [],
            'friday' => [],
            'saturday' => [],
            'sunday' => [],
        ],
    ], 'Ranges on the same day must not overlap.'],
    'interval zero' => [['interval_hours' => 0], 'The reply interval must be at least one hour.'],
    'bad timezone' => [['timezone' => 'Not/AZone'], 'That timezone is not known.'],
]);

it('opens on saturday when that day has a range', function (): void {
    $hours = PracticeHours::fromConfig(practiceHoursConfig([
        'interval_hours' => 12,
        'hours' => [
            'monday' => [],
            'tuesday' => [],
            'wednesday' => [],
            'thursday' => [],
            'friday' => [],
            'saturday' => [['09:00', '13:00']],
            'sunday' => [],
        ],
    ]));
    $zone = $hours->zone();
    $clock = new ReplyClock($hours);

    $schedule = $clock->schedule(new DateTimeImmutable('2026-10-24 10:00', $zone));

    expect($schedule->clockStart->format('Y-m-d H:i'))->toBe('2026-10-24 10:00')
        ->and($schedule->replyDue->format('Y-m-d H:i'))->toBe('2026-10-24 22:00');
});

it('starts after a mid-day gap at the next open', function (): void {
    $hours = PracticeHours::fromConfig(practiceHoursConfig([
        'hours' => [
            'monday' => [['08:00', '12:00'], ['14:00', '18:00']],
            'tuesday' => [],
            'wednesday' => [],
            'thursday' => [],
            'friday' => [],
            'saturday' => [],
            'sunday' => [],
        ],
    ]));
    $zone = $hours->zone();
    $clock = new ReplyClock($hours);

    $schedule = $clock->schedule(new DateTimeImmutable('2026-10-26 13:00', $zone));

    expect($schedule->clockStart->format('Y-m-d H:i'))->toBe('2026-10-26 14:00')
        ->and($clock->openMinutes(
            new DateTimeImmutable('2026-10-26 11:00', $zone),
            new DateTimeImmutable('2026-10-26 15:00', $zone),
        ))->toBe(120);
});
