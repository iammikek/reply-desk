<?php

use App\Desk\DeskException;
use App\Desk\ListOpenReplies;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds an overdue row and an open row with a draft', function () {
    $this->travelTo(london('2026-10-23 12:00'));

    $this->seed(DemoSeeder::class);

    $open = app(ListOpenReplies::class)(london('2026-10-23 12:00'));

    expect($open)->toHaveCount(2)
        ->and(collect($open)->firstWhere('client', 'Northwharf Bakery / Ada North')->overdueAt(london('2026-10-23 12:00')))->toBeTrue()
        ->and(collect($open)->firstWhere('client', 'Harbour Cafe / Ned Shore')->overdueAt(london('2026-10-23 12:00')))->toBeFalse()
        ->and(collect($open)->firstWhere('client', 'Northwharf Bakery / Ada North')->draftSubject)->toBe('PHP on the new server');
});

it('refuses to seed the live portal', function () {
    config(['reply-desk.portal_id' => '145255358']);

    expect(fn () => $this->seed(DemoSeeder::class))
        ->toThrow(DeskException::class, 'never against portal 145255358');
});

it('refuses to seed a hubspot store', function () {
    config([
        'reply-desk.store' => 'hubspot',
        'reply-desk.portal_id' => '999',
    ]);

    expect(fn () => $this->seed(DemoSeeder::class))
        ->toThrow(DeskException::class, 'only runs on the memory store');
});
