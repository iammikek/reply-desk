<?php

use App\Desk\Mailbox\FakeMailbox;
use App\Desk\Mailbox\Mailbox;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Unit');

pest()->extend(TestCase::class)
    ->beforeEach(fn () => Cache::flush())
    ->in('Feature');

function london(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('Europe/London'));
}

function replyDeskParty(string $kind, string $id, string $name, string $email = '', ?string $companyId = null): void
{
    if ($kind === 'company') {
        DB::table('companies')->insert([
            'id' => $id,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return;
    }

    DB::table('contacts')->insert([
        'id' => $id,
        'company_id' => $companyId,
        'name' => $name,
        'email' => $email === '' ? null : strtolower($email),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return list<string>
 */
function replyDeskNoteBodies(): array
{
    return array_map(
        fn (StoredNote $note): string => $note->body,
        app(NoteStore::class)->notes(),
    );
}

function seedReplyDeskDirectory(string $at = '2026-10-23 15:30'): void
{
    test()->travelTo(london($at));
    replyDeskParty('company', '10', 'Northwharf Bakery');
    replyDeskParty('contact', '20', 'Ada North', 'ada@northwharf.example', '10');
}

function useFakeMailbox(string $at = '2026-10-23 15:30'): void
{
    config([
        'reply-desk.mailbox.driver' => 'fake',
        'reply-desk.mailbox.from_address' => 'desk@example.com',
    ]);
    app()->forgetInstance(Mailbox::class);
    app()->forgetInstance(FakeMailbox::class);

    seedReplyDeskDirectory($at);
}
