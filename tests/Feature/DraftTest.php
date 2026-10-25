<?php

use App\Clock\ReceiptSource;
use App\Desk\Actions\DiscardDraft;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\MarkReplySent;
use App\Desk\Actions\SaveDraft;
use App\Desk\Queue\ListOpenReplies;
use App\Filament\Resources\Inbounds\Pages\ListOpenQueue;
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
    replyDeskParty('contact', '20', 'Ada North', 'ada@northwharf.example', '10');
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

it('keeps a pasted draft', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    $body = "Thanks.\n\n    I will  bake it.";

    app(SaveDraft::class)($id, 'The loaf', $body);

    expect(app(ListOpenReplies::class)(london('2026-10-23 12:00'))[0]->draftBody)->toBe($body);
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

    Livewire::test(ListOpenQueue::class)
        ->mountAction(TestAction::make('reply')->table($id))
        ->setActionData([
            'subject' => 'PHP on the new server',
            'body' => 'Use 8.3 on the new box.',
        ])
        ->mountAction('cancel')
        ->assertMountedActionModalSee('Save this as a draft?')
        ->callMountedAction()
        ->assertSee('Draft');

    $draft = Draft::query()->first();

    expect(app(ListOpenReplies::class)(london('2026-10-23 12:00')))->toHaveCount(1)
        ->and($draft->subject)->toBe('PHP on the new server')
        ->and($draft->body)->toBe('Use 8.3 on the new box.');
});

it('closes an unchanged draft without asking to save it', function () {
    $this->actingAs(User::factory()->create());

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3 on the new box.');
    $savedAt = Draft::query()->first()->updated_at;

    $this->travel(1)->minutes();

    $component = Livewire::test(ListOpenQueue::class)
        ->mountAction(TestAction::make('reply')->table($id));

    expect($component->instance()->mountedActions[0]['data']['subject'])->toBe('PHP on the new server')
        ->and($component->instance()->mountedActions[0]['data']['body'])->toBe('Use 8.3 on the new box.');

    $component
        ->mountAction('cancel')
        ->assertSet('mountedActions', []);

    $draft = Draft::query()->first();

    expect($draft->subject)->toBe('PHP on the new server')
        ->and($draft->body)->toBe('Use 8.3 on the new box.')
        ->and($draft->updated_at->equalTo($savedAt))->toBeTrue();
});

it('asks to save when a saved draft was edited', function () {
    $this->actingAs(User::factory()->create());

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3 on the new box.');

    Livewire::test(ListOpenQueue::class)
        ->mountAction(TestAction::make('reply')->table($id))
        ->setActionData([
            'subject' => 'PHP on the new server',
            'body' => 'Use 8.4 on the new box.',
        ])
        ->mountAction('cancel')
        ->assertMountedActionModalSee('Save this as a draft?')
        ->mountAction('cancel')
        ->assertSet('mountedActions', []);

    expect(Draft::query()->first()->body)->toBe('Use 8.3 on the new box.');

    Livewire::test(ListOpenQueue::class)
        ->mountAction(TestAction::make('reply')->table($id))
        ->setActionData([
            'subject' => 'A different subject',
            'body' => 'Use 8.3 on the new box.',
        ])
        ->mountAction('cancel')
        ->assertMountedActionModalSee('Save this as a draft?')
        ->callMountedAction();

    expect(Draft::query()->first()->subject)->toBe('A different subject')
        ->and(Draft::query()->first()->body)->toBe('Use 8.3 on the new box.');
});

it('closes reply without a draft when cancel has no message', function () {
    $this->actingAs(User::factory()->create());

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(ListOpenQueue::class)
        ->mountAction(TestAction::make('reply')->table($id))
        ->mountAction('cancel')
        ->assertSet('mountedActions', []);

    expect(Draft::query()->count())->toBe(0);
});

it('discards the message when cancel declines the draft', function () {
    $this->actingAs(User::factory()->create());

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(ListOpenQueue::class)
        ->mountAction(TestAction::make('reply')->table($id))
        ->setActionData([
            'subject' => 'PHP on the new server',
            'body' => 'Use 8.3 on the new box.',
        ])
        ->mountAction('cancel')
        ->mountAction('cancel')
        ->assertSet('mountedActions', []);

    expect(Draft::query()->count())->toBe(0);
});

it('discards a draft from the queue and leaves the row open', function () {
    $this->actingAs(User::factory()->create());

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3 on the new box.');

    Livewire::test(ListOpenQueue::class)
        ->callAction(TestAction::make('discardDraft')->table($id))
        ->assertSee('Northwharf Bakery');

    expect(Draft::query()->count())->toBe(0)
        ->and(app(ListOpenReplies::class)(london('2026-10-23 12:00')))->toHaveCount(1);
});
