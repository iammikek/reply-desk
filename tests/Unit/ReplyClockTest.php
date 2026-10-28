<?php

use App\Clock\ReplyClock;

it('schedules a reply from the working window', function (string $receipt, string $clockStart, string $replyDue): void {
    $zone = new DateTimeZone(ReplyClock::ZONE);
    $schedule = app(ReplyClock::class)->schedule(new DateTimeImmutable($receipt, $zone));

    expect($schedule->clockStart->setTimezone($zone)->format('Y-m-d H:i'))->toBe($clockStart)
        ->and($schedule->replyDue->setTimezone($zone)->format('Y-m-d H:i'))->toBe($replyDue);
})->with([
    'Tuesday 10:00' => ['2026-10-20 10:00', '2026-10-20 10:00', '2026-10-21 10:00'],
    'Thursday 21:00' => ['2026-10-22 21:00', '2026-10-23 08:00', '2026-10-24 08:00'],
    'Friday 21:00' => ['2026-10-23 21:00', '2026-10-26 08:00', '2026-10-27 08:00'],
    'Saturday' => ['2026-10-24 15:00', '2026-10-26 08:00', '2026-10-27 08:00'],
    'Sunday' => ['2026-10-25 11:00', '2026-10-26 08:00', '2026-10-27 08:00'],
]);
