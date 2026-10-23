<?php

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

function replyDeskParty(string $kind, string $id, string $name, string $email = ''): void
{
    DB::table('fake_parties')->insert([
        'kind' => $kind,
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
