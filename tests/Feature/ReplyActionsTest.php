<?php

use App\Clock\ReceiptSource;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\MarkReplySent;
use App\Desk\DeskException;
use App\Desk\Queue\ListClosedThreads;
use App\Desk\Queue\ListOpenReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    replyDeskParty('company', '10', 'Northwharf Bakery');
    replyDeskParty('contact', '20', 'Ada North', 'ada@northwharf.example', '10');
});

it('lists an open inbound with the due time from the clock', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-22 21:00'),
        ReceiptSource::Logged,
        'Which PHP to use',
    );

    $open = app(ListOpenReplies::class)(london('2026-10-23 09:00'));

    expect($open)->toHaveCount(1)
        ->and($open[0]->id)->toBe($id)
        ->and($open[0]->client)->toBe('Northwharf Bakery / Ada North')
        ->and($open[0]->clockStart->format('Y-m-d H:i'))->toBe('2026-10-23 08:00')
        ->and($open[0]->replyDue->format('Y-m-d H:i'))->toBe('2026-10-24 08:00')
        ->and($open[0]->overdueAt(london('2026-10-23 09:00')))->toBeFalse()
        ->and($open[0]->overdueAt(london('2026-10-24 08:01')))->toBeTrue();
});

it('keeps a later reply and refuses the same minute', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(MarkReplySent::class)($id, london('2026-10-20 16:04'), 'PHP on the new server', 'Use 8.3.');
    app(MarkReplySent::class)($id, london('2026-10-20 18:00'), 'Again', 'Use 8.4.');

    $closed = app(ListClosedThreads::class)();

    expect(app(ListOpenReplies::class)(london('2026-10-20 19:00')))->toBe([])
        ->and($closed)->toHaveCount(1)
        ->and($closed[0]->replies)->toHaveCount(2)
        ->and($closed[0]->replies[1]['subject'])->toBe('Again')
        ->and($closed[0]->replies[1]['body'])->toBe('Use 8.4.');

    expect(fn () => app(MarkReplySent::class)($id, london('2026-10-20 18:00'), 'Again', 'Once more'))
        ->toThrow(DeskException::class, 'already recorded at that minute');
});

it('stops when more than one company matches and writes no note', function () {
    replyDeskParty('company', '11', 'Northwharf Bakery West');

    expect(fn () => app(LogInbound::class)(
        'Northwharf',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::Logged,
        'Which PHP to use',
    ))->toThrow(DeskException::class, 'More than one company matched');

    expect(DB::table('emails')->count())->toBe(0);
});

it('keeps the pasted reply and shows that thread on the next open note', function () {
    $closed = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
        'Which loaf for Friday.',
    );

    Artisan::call('reply:mark-sent', [
        'inbound' => $closed,
        'sent' => '2026-10-20 16:04',
        'subject' => 'PHP on the new server',
        'reply' => "Use 8.3 on the new box.\n\nMike",
    ]);

    $openId = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-21 09:00'),
        ReceiptSource::MailClient,
        'The form can come off',
    );

    $open = app(ListOpenReplies::class)(london('2026-10-21 12:00'));

    expect($open)->toHaveCount(1)
        ->and($open[0]->id)->toBe($openId)
        ->and($open[0]->earlier)->toHaveCount(1)
        ->and($open[0]->earlier[0]->clientEmail)->toBe('Which loaf for Friday.')
        ->and($open[0]->earlier[0]->replies[0]['subject'])->toBe('PHP on the new server')
        ->and($open[0]->earlier[0]->replies[0]['body'])->toBe("Use 8.3 on the new box.\n\nMike");
});

it('lists the contacts on each company', function () {
    replyDeskParty('company', '11', 'Harbour Cafe');
    replyDeskParty('contact', '21', 'Ned Shore', 'ned@harbour.example', '11');
    replyDeskParty('contact', '23', 'No Address', '', '10');

    Artisan::call('reply:directory');
    $directory = json_decode(Artisan::output(), true);
    $northwharf = collect($directory)->firstWhere('id', '10');

    expect($directory)->toHaveCount(2)
        ->and($directory[0]['name'])->toBe('Harbour Cafe')
        ->and($directory[0]['contacts'])->toHaveCount(1)
        ->and($directory[0]['contacts'][0]['email'])->toBe('ned@harbour.example')
        ->and($northwharf['contacts'])->toHaveCount(1)
        ->and($northwharf['contacts'][0]['email'])->toBe('ada@northwharf.example');
});

it('stores the pasted client email on the inbound note', function () {
    Artisan::call('reply:log-inbound', [
        'company' => 'Northwharf Bakery',
        'email' => 'ada@northwharf.example',
        'receipt' => '2026-10-20 10:00',
        'source' => 'mail client',
        'summary' => 'Which PHP to use',
        'client' => "Which loaf for Friday.\n\nAda",
        '--subject' => 'Friday loaf',
    ]);

    expect(replyDeskNoteBodies()[0])
        ->toContain("Which loaf for Friday.\n\nAda")
        ->toContain('Subject: Friday loaf');
});

it('adds a company and a contact', function () {
    Artisan::call('reply:create-company', ['name' => 'West Quay']);
    $company = json_decode(Artisan::output(), true);

    Artisan::call('reply:create-contact', [
        'company' => 'West Quay',
        'name' => 'Bea Quay',
        'email' => 'Bea@West.example',
    ]);
    $contact = json_decode(Artisan::output(), true);

    expect($company['name'])->toBe('West Quay')
        ->and($contact['email'])->toBe('bea@west.example');

    expect(Artisan::call('reply:create-company', ['name' => 'West Quay']))->toBe(1);
});

it('refuses a contact who is not on that company', function () {
    replyDeskParty('company', '11', 'Harbour Cafe');
    replyDeskParty('contact', '21', 'Ned Shore', 'ned@harbour.example', '11');

    expect(fn () => app(LogInbound::class)->record(
        '10',
        '21',
        london('2026-10-20 10:00'),
        ReceiptSource::Logged,
        'Which PHP to use',
    ))->toThrow(DeskException::class, 'No contact matched.');

    expect(DB::table('emails')->count())->toBe(0);
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
