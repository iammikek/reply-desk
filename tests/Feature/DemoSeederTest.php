<?php

use App\Desk\DeskException;
use App\Desk\Queue\ListClosedThreads;
use App\Desk\Queue\ListOpenReplies;
use App\Filament\Schemas\ReplyInfolist;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds an overdue row and an open row with a draft', function (): void {
    $this->travelTo(london('2026-10-23 12:00'));

    $this->seed(DemoSeeder::class);

    $open = app(ListOpenReplies::class)(london('2026-10-23 12:00'));

    expect($open)->toHaveCount(2)
        ->and(collect($open)->firstWhere('client', 'Northwharf Bakery / Ada North')->overdueAt(london('2026-10-23 12:00')))->toBeTrue()
        ->and(collect($open)->firstWhere('client', 'Harbour Cafe / Ned Shore')->overdueAt(london('2026-10-23 12:00')))->toBeFalse()
        ->and(collect($open)->firstWhere('client', 'Northwharf Bakery / Ada North')->draftSubject)->toBe('PHP on the new server');

    $closed = app(ListClosedThreads::class)();
    $thread = $closed[0];
    $sent = $thread->replies[0]['sent']->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i');

    expect($closed)->toHaveCount(1)
        ->and($thread->client)->toBe('Riverside Books / Jo River')
        ->and($thread->summary)->toBe('Reprint of the spring catalogue')
        ->and(ReplyInfolist::repliedIn($thread->receipt->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i'), $sent))->toBe('Replied in 2 hours 30 minutes');
});

it('refuses to seed a hubspot store', function (): void {
    config([
        'reply-desk.store' => 'hubspot',
        'reply-desk.portal_id' => '100000001',
    ]);

    expect(fn () => $this->seed(DemoSeeder::class))
        ->toThrow(DeskException::class, 'only runs on the memory store');
});
