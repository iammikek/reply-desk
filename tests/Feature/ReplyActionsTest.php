<?php

use App\Clock\ReceiptSource;
use App\Desk\DeskException;
use App\Desk\ListOpenReplies;
use App\Desk\LogInbound;
use App\Desk\MarkReplySent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    replyDeskParty('company', '10', 'Northwharf Bakery');
    replyDeskParty('contact', '20', 'Ada North', 'ada@northwharf.example');
});

it('lists an open inbound with the due time from the clock', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-22 21:00'),
        ReceiptSource::Logged,
        'Which PHP to use',
    );

    DB::table('fake_notes')->insert([
        'id' => '99',
        'body' => 'Deposit invoice sent. Open, not paid.',
        'company_id' => '10',
        'company_name' => 'Northwharf Bakery',
        'contact_id' => '20',
        'contact_name' => 'Ada North',
        'contact_email' => 'ada@northwharf.example',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $open = app(ListOpenReplies::class)(london('2026-10-23 09:00'));

    expect($open)->toHaveCount(1)
        ->and($open[0]->id)->toBe($id)
        ->and($open[0]->client)->toBe('Northwharf Bakery / Ada North')
        ->and($open[0]->clockStart->format('Y-m-d H:i'))->toBe('2026-10-23 08:00')
        ->and($open[0]->replyDue->format('Y-m-d H:i'))->toBe('2026-10-24 08:00')
        ->and($open[0]->overdueAt(london('2026-10-23 09:00')))->toBeFalse()
        ->and($open[0]->overdueAt(london('2026-10-24 08:01')))->toBeTrue();
});

it('closes a row when the reply is marked sent and refuses a second close', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(MarkReplySent::class)($id, london('2026-10-20 16:04'), 'PHP on the new server');

    expect(app(ListOpenReplies::class)(london('2026-10-20 17:00')))->toBe([]);

    app(MarkReplySent::class)($id, london('2026-10-20 18:00'), 'Again');
})->throws(DeskException::class, 'already closed');

it('stops when more than one company matches and writes no note', function () {
    replyDeskParty('company', '11', 'Northwharf Bakery');

    expect(fn () => app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::Logged,
        'Which PHP to use',
    ))->toThrow(DeskException::class, 'More than one company matched');

    expect(DB::table('fake_notes')->count())->toBe(0);
});

it('prints the open queue as json', function () {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Artisan::call('reply:list-open', ['--now' => '2026-10-20 12:00']);
    $output = Artisan::output();

    expect($output)->toContain('Northwharf Bakery / Ada North')
        ->and($output)->toContain('2026-10-21 10:00');
});
