<?php

use App\Clock\ReceiptSource;
use App\Desk\Actions\DiscardDraft;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\SaveDraft;
use App\Desk\Actions\SendReply;
use App\Desk\DeskException;
use App\Desk\Mailbox\FakeMailbox;
use App\Desk\Mailbox\Mailbox;
use App\Desk\Queue\ListOpenReplies;
use App\Mail\ClientReply;
use App\Models\DeskActivity;
use App\Models\Draft;
use App\Models\MailMessageId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(fn () => useFakeMailbox());

it('creates and replaces one drafts folder message', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'Subject one', 'Body one');
    $draft = Draft::query()->first();
    $mailbox = app(FakeMailbox::class);

    expect($draft?->mailbox_draft_id)->not->toBeNull()
        ->and($mailbox->drafts)->toHaveCount(1)
        ->and($mailbox->drafts[$draft->mailbox_draft_id]['body'])->toBe('Body one');

    app(SaveDraft::class)($id, 'Subject two', 'Body two');
    $draft->refresh();

    expect($mailbox->drafts)->toHaveCount(1)
        ->and($mailbox->drafts[$draft->mailbox_draft_id]['body'])->toBe('Body two');
});

it('removes the drafts folder message on discard', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'Subject', 'Body');
    $identity = Draft::query()->value('mailbox_draft_id');

    app(DiscardDraft::class)($id);

    expect(Draft::query()->count())->toBe(0)
        ->and(app(FakeMailbox::class)->drafts)->not->toHaveKey($identity);
});

it('stores a Message-ID and clears drafts on send', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3.');
    $sentId = app(SendReply::class)($id, 'PHP on the new server', 'Use 8.3.');

    expect(app(ListOpenReplies::class)(london('2026-10-23 15:30')))->toBeEmpty()
        ->and(Draft::query()->count())->toBe(0)
        ->and(app(FakeMailbox::class)->drafts)->toBeEmpty()
        ->and(app(FakeMailbox::class)->sent)->toHaveCount(1)
        ->and(MailMessageId::query()->where('note_id', $sentId)->exists())->toBeTrue()
        ->and(DeskActivity::query()->where('action', 'reply.sent')->exists())->toBeTrue();
});

it('leaves both drafts when send is refused', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3.');
    app(FakeMailbox::class)->refuseSend = true;

    expect(fn () => app(SendReply::class)($id, 'PHP on the new server', 'Use 8.3.'))
        ->toThrow(DeskException::class, 'The mail server refused the message.');

    expect(Draft::query()->count())->toBe(1)
        ->and(app(FakeMailbox::class)->drafts)->toHaveCount(1)
        ->and(app(ListOpenReplies::class)(london('2026-10-23 15:30')))->toHaveCount(1)
        ->and(DeskActivity::query()->where('action', 'reply.send_failed')->exists())->toBeTrue();
});

it('sends through reply:send', function () {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Artisan::call('reply:send', [
        'inbound' => $id,
        'subject' => 'PHP',
        'body' => 'Use 8.3.',
    ]);

    expect(app(ListOpenReplies::class)(london('2026-10-23 15:30')))->toBeEmpty();
});

it('keeps the laravel mailer path when mailbox is off', function () {
    config(['reply-desk.mailbox.driver' => '']);
    app()->forgetInstance(Mailbox::class);
    Mail::fake();

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'Subject', 'Body');

    expect(Draft::query()->value('mailbox_draft_id'))->toBeNull();

    app(SendReply::class)($id, 'Subject', 'Body');

    Mail::assertSent(ClientReply::class);
});
