<?php

use App\Clock\ReceiptSource;
use App\Desk\ListClosedThreads;
use App\Desk\LogInbound;
use App\Desk\MarkReplySent;
use App\Filament\Pages\History;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(london('2026-10-23 12:00'));
    $this->actingAs(User::factory()->create());
    replyDeskParty('company', '10', 'Northwharf Bakery');
    replyDeskParty('contact', '20', 'Ada North', 'ada@northwharf.example', '10');
    replyDeskParty('company', '11', 'Harbour Cafe');
    replyDeskParty('contact', '21', 'Ned Harbour', 'ned@harbour.example', '11');
});

it('lists a closed thread when that company has nothing open', function () {
    $closed = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
        'Which loaf for Friday.',
    );

    app(MarkReplySent::class)($closed, london('2026-10-20 16:04'), 'PHP on the new server', "Use 8.3 on the new box.\n\nMike");

    app(LogInbound::class)(
        'Harbour Cafe',
        'ned@harbour.example',
        london('2026-10-23 09:00'),
        ReceiptSource::MailClient,
        'Still waiting',
    );

    $threads = app(ListClosedThreads::class)();

    expect($threads)->toHaveCount(1)
        ->and($threads[0]->id)->toBe($closed)
        ->and($threads[0]->client)->toBe('Northwharf Bakery / Ada North')
        ->and($threads[0]->clientEmail)->toBe('Which loaf for Friday.')
        ->and($threads[0]->replies[0]['subject'])->toBe('PHP on the new server')
        ->and($threads[0]->replies[0]['body'])->toBe("Use 8.3 on the new box.\n\nMike");

    Livewire::test(History::class)
        ->assertCanSeeTableRecords([$closed])
        ->assertSee('Northwharf Bakery / Ada North')
        ->assertSee('Which PHP to use')
        ->assertSee('PHP on the new server')
        ->assertDontSee('Harbour Cafe')
        ->assertDontSee('Still waiting')
        ->mountAction(TestAction::make('view')->table($closed))
        ->assertSeeInOrder([
            '2026-10-20 10:00',
            'Client email',
            'Which PHP to use',
            'Which loaf for Friday.',
            '2026-10-20 16:04',
            'Reply',
            'PHP on the new server',
            'Use 8.3 on the new box.',
        ]);
});

it('orders closed threads by the latest sent time', function () {
    $older = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );
    $newer = app(LogInbound::class)(
        'Harbour Cafe',
        'ned@harbour.example',
        london('2026-10-18 11:00'),
        ReceiptSource::MailClient,
        'The till tape',
    );

    app(MarkReplySent::class)($older, london('2026-10-20 16:04'), 'PHP on the new server', 'Use 8.3.');
    app(MarkReplySent::class)($newer, london('2026-10-22 09:15'), 'Till tape received', 'Got it.');

    $threads = app(ListClosedThreads::class)();

    expect($threads[0]->id)->toBe($newer)
        ->and($threads[1]->id)->toBe($older);
});

it('shows the newest closed threads on the first page', function () {
    $oldest = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-18 10:00'),
        ReceiptSource::MailClient,
        'The oldest loaf',
    );
    $middle = app(LogInbound::class)(
        'Harbour Cafe',
        'ned@harbour.example',
        london('2026-10-19 11:00'),
        ReceiptSource::MailClient,
        'The middle loaf',
    );
    $newest = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-22 09:00'),
        ReceiptSource::MailClient,
        'The newest loaf',
    );

    app(MarkReplySent::class)($oldest, london('2026-10-18 16:00'), 'Oldest reply', 'First.');
    app(MarkReplySent::class)($middle, london('2026-10-20 12:00'), 'Middle reply', 'Second.');
    app(MarkReplySent::class)($newest, london('2026-10-22 09:30'), 'Newest reply', 'Third.');

    Livewire::test(History::class)
        ->set('tableRecordsPerPage', 2)
        ->assertCountTableRecords(3)
        ->assertSee('Showing 1 to 2 of 3 results')
        ->assertCanSeeTableRecords([$newest, $middle])
        ->assertCanNotSeeTableRecords([$oldest])
        ->assertSee('The newest loaf')
        ->assertSee('The middle loaf')
        ->assertDontSee('The oldest loaf')
        ->call('nextPage')
        ->assertSee('Showing 3 to 3 of 3 results')
        ->assertCanSeeTableRecords([$oldest])
        ->assertCanNotSeeTableRecords([$newest, $middle])
        ->assertSee('The oldest loaf')
        ->assertDontSee('The newest loaf');
});
