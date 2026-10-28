<?php

use App\Clock\ReceiptSource;
use App\Desk\Actions\CloseInbound;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\SaveDraft;
use App\Desk\Actions\SendReply;
use App\Desk\DeskException;
use App\Desk\Queue\ListClientCorrespondence;
use App\Desk\Queue\ListClosedThreads;
use App\Desk\Queue\ListOpenReplies;
use App\Filament\Resources\Inbounds\Pages\ListOpenQueue;
use App\Models\DeskActivity;
use App\Models\Draft;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedReplyDeskDirectory('2026-10-28 12:00');
    $this->actingAs(User::factory()->create());
});

it('closes an inbound without a reply and removes it from the open queue', function (): void {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-28 11:45'),
        ReceiptSource::MailClient,
        'Thanks and next steps',
        "thanks for confirmation. I'll keep you updated.",
    );

    app(SaveDraft::class)($id, 'Draft', 'Would have replied');

    $closedId = app(CloseInbound::class)($id, london('2026-10-28 13:15'));

    expect(app(ListOpenReplies::class)(london('2026-10-28 14:00')))->toBeEmpty()
        ->and(Draft::query()->count())->toBe(0)
        ->and(DeskActivity::query()->where('action', 'inbound.closed')->where('contact_id', '20')->exists())->toBeTrue();

    $threads = app(ListClosedThreads::class)();

    expect($threads)->toHaveCount(1)
        ->and($threads[0]->id)->toBe($id)
        ->and($threads[0]->replies)->toBeEmpty()
        ->and($threads[0]->closedAt?->format('Y-m-d H:i'))->toBe('2026-10-28 13:15')
        ->and($threads[0]->toArray()['subject'])->toBe('No reply')
        ->and($threads[0]->toArray()['closed_without_reply'])->toBeTrue();

    $correspondence = app(ListClientCorrespondence::class)('10', '20');

    expect($correspondence)->not->toBeNull()
        ->and(collect($correspondence->events)->contains(
            fn (array $event): bool => $event['kind'] === 'Closed' && $event['title'] === 'No reply',
        ))->toBeTrue();

    expect(fn () => app(CloseInbound::class)($id))->toThrow(DeskException::class, 'already closed');
    expect(fn () => app(SendReply::class)($id, 'Subject', 'Body'))
        ->toThrow(DeskException::class, 'already closed');

    expect($closedId)->not->toBe($id);
});

it('closes from the open queue action', function (): void {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-28 11:45'),
        ReceiptSource::MailClient,
        'Thanks',
        'Speak soon.',
    );

    Livewire::test(ListOpenQueue::class)
        ->callAction(TestAction::make('close')->table($id))
        ->assertNotified('Inbound closed')
        ->assertDontSee('Thanks');

    expect(app(ListOpenReplies::class)())->toBeEmpty();
});

it('closes through reply:close', function (): void {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-28 11:45'),
        ReceiptSource::MailClient,
        'Thanks',
    );

    expect(Artisan::call('reply:close', [
        'inbound' => $id,
        '--at' => '2026-10-28 13:20',
    ]))->toBe(0)
        ->and(app(ListOpenReplies::class)())->toBeEmpty()
        ->and(app(ListClosedThreads::class)()[0]->closedAt?->format('Y-m-d H:i'))->toBe('2026-10-28 13:20');
});
