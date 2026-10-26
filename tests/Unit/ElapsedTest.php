<?php

use App\Clock\Elapsed;
use App\Clock\ReplyClock;
use App\Filament\Schemas\ReplyInfolist;

it('states how long a reply took from the clock start', function (string $start, string $end, string $elapsed) {
    $zone = new DateTimeZone(ReplyClock::ZONE);

    expect(Elapsed::between(new DateTimeImmutable($start, $zone), new DateTimeImmutable($end, $zone)))->toBe($elapsed);
})->with([
    'same minute' => ['2026-10-20 10:00', '2026-10-20 10:00', 'under a minute'],
    'one minute' => ['2026-10-20 10:00', '2026-10-20 10:01', '1 minute'],
    'hours and minutes' => ['2026-10-20 10:00', '2026-10-20 16:04', '6 hours 4 minutes'],
    'one day' => ['2026-10-20 10:00', '2026-10-21 10:00', '1 day'],
    'a day and an hour' => ['2026-10-20 10:00', '2026-10-21 11:00', '1 day 1 hour'],
]);

it('counts a reply from the next working morning', function () {
    expect(ReplyInfolist::repliedIn('2026-10-23 21:00', '2026-10-26 09:30'))->toBe('Replied in 1 hour 30 minutes');
});

it('places a later clock start after the receipt', function () {
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
