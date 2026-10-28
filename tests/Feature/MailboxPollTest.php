<?php

use App\Clock\ReceiptSource;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\PollMailbox;
use App\Desk\Actions\SendReply;
use App\Desk\Mailbox\FakeMailbox;
use App\Desk\Mailbox\Mailbox;
use App\Desk\Queue\ListOpenReplies;
use App\Models\DeskActivity;
use App\Models\MailboxState;
use App\Models\MailMessageId;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(fn () => useFakeMailbox());

it('logs a known contact from the mailbox', function (): void {
    $mailbox = app(FakeMailbox::class);
    $mailbox->seed(FakeMailbox::message(
        'ada@northwharf.example',
        'Which PHP to use',
        "Can you look today?\n\n> Old mail",
        london('2026-10-22 10:00'),
        cursor: '000001',
    ));

    $result = app(PollMailbox::class)(force: true);

    expect($result['kept'])->toBe(1)
        ->and($result['skipped'])->toBe(0)
        ->and(app(ListOpenReplies::class)(london('2026-10-23 15:30')))->toHaveCount(1);

    $bodies = replyDeskNoteBodies();
    expect($bodies[0])->toContain("Client email:\nCan you look today?")
        ->and($bodies[0])->not->toContain('> Old mail')
        ->and($bodies[0])->toContain('Receipt source: mail client');
});

it('skips unknown from, own from, date-only, and duplicates', function (): void {
    $mailbox = app(FakeMailbox::class);
    $mailbox->seed(FakeMailbox::message('stranger@example.com', 'Hi', 'Hello', london('2026-10-22 10:00'), cursor: '1'));
    $mailbox->seed(FakeMailbox::message('desk@example.com', 'Self', 'Hello', london('2026-10-22 10:01'), cursor: '2'));
    $mailbox->seed(FakeMailbox::message('ada@northwharf.example', 'No time', 'Hello', null, cursor: '3'));
    $mailbox->seed(FakeMailbox::message(
        'ada@northwharf.example',
        'Keep',
        'Hello',
        london('2026-10-22 10:02'),
        messageId: '<dup@test>',
        cursor: '4',
    ));
    MailMessageId::remember('<dup@test>', '999', 'inbound');

    $result = app(PollMailbox::class)(force: true);

    expect($result['kept'])->toBe(0)
        ->and($result['skipped'])->toBe(4)
        ->and(app(ListOpenReplies::class)(london('2026-10-23 15:30')))->toBeEmpty();
});

it('logs a reply when In-Reply-To matches a stored sent Message-ID', function (): void {
    Mail::fake();
    config(['reply-desk.mailbox.driver' => '']);
    app()->forgetInstance(Mailbox::class);

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );
    app(SendReply::class)($id, 'PHP', 'Use 8.3.');

    config(['reply-desk.mailbox.driver' => 'fake', 'reply-desk.mailbox.from_address' => 'desk@example.com']);
    app()->forgetInstance(Mailbox::class);
    app()->forgetInstance(FakeMailbox::class);

    MailMessageId::remember('<sent-1@test>', 'sent-note', 'sent');

    $mailbox = app(FakeMailbox::class);
    $mailbox->seed(FakeMailbox::message(
        'ada@northwharf.example',
        'Re: PHP',
        'Thanks',
        london('2026-10-22 11:00'),
        messageId: '<reply-1@test>',
        inReplyTo: '<sent-1@test>',
        cursor: '10',
    ));

    $result = app(PollMailbox::class)(force: true);

    expect($result['kept'])->toBe(1)
        ->and(DeskActivity::query()->where('action', 'inbound.logged')->exists())->toBeTrue();
});

it('no-ops when polling is disabled unless forced', function (): void {
    MailboxState::current()->forceFill([
        'polling_enabled' => false,
        'poll_interval_minutes' => 5,
    ])->save();

    app(FakeMailbox::class)->seed(FakeMailbox::message(
        'ada@northwharf.example',
        'Hi',
        'Body',
        london('2026-10-22 10:00'),
        cursor: '1',
    ));

    expect(app(PollMailbox::class)(force: false)['noop'])->toBeTrue()
        ->and(app(ListOpenReplies::class)(london('2026-10-23 15:30')))->toBeEmpty();

    expect(app(PollMailbox::class)(force: true)['kept'])->toBe(1);
});

it('skips a scheduled run when the interval has not elapsed', function (): void {
    MailboxState::current()->forceFill([
        'polling_enabled' => true,
        'poll_interval_minutes' => 30,
        'last_polled_at' => now(),
    ])->save();

    app(FakeMailbox::class)->seed(FakeMailbox::message(
        'ada@northwharf.example',
        'Hi',
        'Body',
        london('2026-10-22 10:00'),
        cursor: '1',
    ));

    expect(app(PollMailbox::class)(force: false)['noop'])->toBeTrue();
});

it('shows the activity page', function (): void {
    $user = User::factory()->create();
    DeskActivity::query()->create([
        'action' => 'poll.started',
        'summary' => 'Mailbox poll started.',
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/activity')
        ->assertSuccessful()
        ->assertSee('Mailbox poll started.');
});
