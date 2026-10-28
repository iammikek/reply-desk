<?php

use App\Clock\ClockNote;
use App\Clock\ReceiptSource;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\SaveDraft;
use App\Desk\DeskException;
use App\Desk\Notes\NoteStore;
use App\Models\Draft;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedReplyDeskDirectory());

it('stores a draft against the local email', function (): void {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3 on the new box.');

    $draft = Draft::query()->first();

    expect($draft)->not->toBeNull()
        ->and((string) $draft->email_id)->toBe($id)
        ->and($draft->inbound_note_id)->toBeNull();
});

it('refuses a draft that points at both records', function (): void {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    expect(fn () => Draft::query()->create([
        'email_id' => $id,
        'inbound_note_id' => '55',
        'subject' => 'PHP on the new server',
        'body' => 'Use 8.3.',
    ]))->toThrow(DeskException::class, 'local email or the HubSpot note');
});

it('refuses a second reply at the same minute', function (): void {
    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    $notes = app(NoteStore::class);
    $notes->writeNote(ClockNote::sent($id, london('2026-10-20 16:04'), 'PHP on the new server', 'Use 8.3.'), '10', '20');

    expect(fn () => $notes->writeNote(ClockNote::sent($id, london('2026-10-20 16:04'), 'Again', 'Use 8.4.'), '10', '20'))
        ->toThrow(DeskException::class, 'already recorded at that minute');
});

it('keeps a HubSpot draft on the note id', function (): void {
    config(['reply-desk.store' => 'hubspot']);

    Draft::query()->create([
        'inbound_note_id' => '55',
        'subject' => 'Friday loaf',
        'body' => 'Use the tin loaf.',
    ]);

    expect(Draft::query()->where('inbound_note_id', '55')->value('subject'))->toBe('Friday loaf')
        ->and(Draft::query()->whereNotNull('email_id')->count())->toBe(0);
});
