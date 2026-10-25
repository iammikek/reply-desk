<?php

use App\Desk\Notes\NoteStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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
        fn ($note): string => $note->body,
        app(NoteStore::class)->notes(),
    );
}
