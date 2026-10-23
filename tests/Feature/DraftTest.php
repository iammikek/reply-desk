<?php

use App\Clock\ReceiptSource;
use App\Desk\DiscardDraft;
use App\Desk\ListOpenReplies;
use App\Desk\LogInbound;
use App\Desk\MarkReplySent;
use App\Desk\SaveDraft;
use App\Filament\Pages\OpenQueue;
use App\Models\Draft;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(london('2026-10-23 12:00'));
    replyDeskParty('company', '10', 'Northwharf Bakery');
    replyDeskParty('contact', '20', 'Ada North', 'ada@northwharf.example');
});

it('keeps the row open when a draft is saved', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    $before = app(ListOpenReplies::class)(london('2026-10-23 12:00'))[0]->replyDue;

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3 on the new box.');

    $open = app(ListOpenReplies::class)(london('2026-10-23 12:00'));

    expect($open)->toHaveCount(1)
        ->and($open[0]->replyDue->format('Y-m-d H:i'))->toBe($before->format('Y-m-d H:i'))
        ->and($open[0]->draftSubject)->toBe('PHP on the new server')
        ->and($open[0]->draftBody)->toBe('Use 8.3 on the new box.');

    app(DiscardDraft::class)($id);

    expect(app(ListOpenReplies::class)(london('2026-10-23 12:00'))[0]->draftSubject)->toBeNull()
        ->and(Draft::query()->count())->toBe(0);
});

it('deletes the draft when the reply is marked sent', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::Logged,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3.');
    app(MarkReplySent::class)($id, london('2026-10-23 15:00'), 'PHP on the new server');

    expect(Draft::query()->count())->toBe(0);
});

it('returns the same draft from the command', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Artisan::call('reply:save-draft', [
        'inbound' => $id,
        'subject' => 'PHP on the new server',
        'body' => 'Use 8.3 on the new box.',
    ]);

    Artisan::call('reply:list-open', ['--now' => '2026-10-23 12:00']);
    $output = Artisan::output();

    expect($output)->toContain('Use 8.3 on the new box.')
        ->and($output)->toContain('2026-10-21 10:00');
});

it('shows a saved draft on the queue', function () {
    $this->actingAs(User::factory()->create());

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(OpenQueue::class)
        ->callAction(TestAction::make('saveDraft')->table($id), [
            'subject' => 'PHP on the new server',
            'body' => 'Use 8.3 on the new box.',
        ])
        ->assertSee('Draft');

    expect(app(ListOpenReplies::class)(london('2026-10-23 12:00')))->toHaveCount(1);
});
