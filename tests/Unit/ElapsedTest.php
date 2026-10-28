<?php

use App\Clock\Elapsed;
use App\Clock\PracticeHours;
use App\Clock\ReplyClock;
use App\Filament\Schemas\ReplyInfolist;

it('states how long a reply took from the clock start', function (string $start, string $end, string $elapsed): void {
    $zone = new DateTimeZone(ReplyClock::ZONE);

    expect(Elapsed::between(new DateTimeImmutable($start, $zone), new DateTimeImmutable($end, $zone)))->toBe($elapsed);
})->with([
    'same minute' => ['2026-10-20 10:00', '2026-10-20 10:00', 'under a minute'],
    'one minute' => ['2026-10-20 10:00', '2026-10-20 10:01', '1 minute'],
    'hours and minutes' => ['2026-10-20 10:00', '2026-10-20 16:04', '6 hours 4 minutes'],
    'one day' => ['2026-10-20 10:00', '2026-10-21 10:00', '1 day'],
    'a day and an hour' => ['2026-10-20 10:00', '2026-10-21 11:00', '1 day 1 hour'],
]);

it('counts a reply from the next working morning', function (): void {
    expect(ReplyInfolist::repliedIn('2026-10-23 21:00', '2026-10-26 09:30'))->toBe('Replied in 1 hour 30 minutes');
});

it('leaves the weekend and the evening out of replied in', function (): void {
    expect(ReplyInfolist::repliedIn('2026-10-23 09:40', '2026-10-26 19:30'))->toBe('Replied in 21 hours 50 minutes')
        ->and(ReplyInfolist::repliedIn('2026-10-20 10:00', '2026-10-21 10:00'))->toBe('Replied in 12 hours');
});

it('leaves a mid-day gap out of open minutes', function (): void {
    $hours = PracticeHours::fromConfig([
        'timezone' => ReplyClock::ZONE,
        'interval_hours' => 24,
        'hours' => [
            'monday' => [['08:00', '12:00'], ['14:00', '18:00']],
            'tuesday' => [['08:00', '20:00']],
            'wednesday' => [['08:00', '20:00']],
            'thursday' => [['08:00', '20:00']],
            'friday' => [['08:00', '20:00']],
            'saturday' => [],
            'sunday' => [],
        ],
    ]);
    $zone = $hours->zone();
    $clock = new ReplyClock($hours);

    expect($clock->openMinutes(
        new DateTimeImmutable('2026-10-26 11:00', $zone),
        new DateTimeImmutable('2026-10-26 15:00', $zone),
    ))->toBe(120);
});

it('places a later clock start after the receipt', function (): void {
    $kinds = collect(ReplyInfolist::events([
        'inbound' => '2026-10-23 21:00',
        'clock_start' => '2026-10-26 08:00',
        'reply_due' => '2026-10-27 08:00',
        'summary' => 'After hours',
        'receipt_source' => 'Mail client',
        'body' => '',
    ]))->pluck('kind')->all();

    expect($kinds)->toBe(['Client email', 'Clock start', 'Reply due']);
});
