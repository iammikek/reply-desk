<?php

use App\Desk\Queue\ListClosedThreads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('runs every desk command', function () {
    expect(Artisan::call('reply:backfill', [
        'path' => base_path('tests/Fixtures/open-table.md'),
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('Dry run. Nothing was written.');

    expect(Artisan::call('reply:create-company', ['name' => 'West Quay']))->toBe(0);
    expect(Artisan::call('reply:create-contact', [
        'company' => 'West Quay',
        'name' => 'Bea Quay',
        'email' => 'Bea@West.example',
    ]))->toBe(0)
        ->and(json_decode(Artisan::output(), true)['email'])->toBe('bea@west.example');

    expect(Artisan::call('reply:directory'))->toBe(0)
        ->and(Artisan::output())->toContain('West Quay');

    expect(Artisan::call('reply:log-inbound', [
        'company' => 'West Quay',
        'email' => 'bea@west.example',
        'receipt' => '2026-10-20 10:00',
        'source' => 'mail client',
        'summary' => 'Which PHP to use',
        'client' => 'Which loaf for Friday.',
        '--subject' => 'Friday loaf',
    ]))->toBe(0);

    $inbound = json_decode(Artisan::output(), true)['id'];

    expect(Artisan::call('reply:list-open', ['--now' => '2026-10-20 12:00']))->toBe(0)
        ->and(Artisan::output())->toContain('Which PHP to use');

    expect(Artisan::call('reply:save-draft', [
        'inbound' => $inbound,
        'subject' => 'Friday loaf',
        'body' => 'Use 8.3.',
    ]))->toBe(0);

    expect(Artisan::call('reply:list-open', ['--now' => '2026-10-20 12:00']))->toBe(0)
        ->and(Artisan::output())->toContain('Use 8.3.');

    expect(Artisan::call('reply:discard-draft', ['inbound' => $inbound]))->toBe(0);

    expect(Artisan::call('reply:list-open', ['--now' => '2026-10-20 12:00']))->toBe(0)
        ->and(Artisan::output())->not->toContain('Use 8.3.');

    expect(Artisan::call('reply:mark-sent', [
        'inbound' => $inbound,
        'sent' => '2026-10-20 16:04',
        'reply' => 'The Saturday menu can go out on Friday.',
        '--subject' => 'Friday loaf',
    ]))->toBe(0);

    expect(Artisan::call('reply:list-closed'))->toBe(0);
    $closed = Artisan::output();

    expect($closed)->toContain('Friday loaf')
        ->and($closed)->toContain('West Quay / Bea Quay');
});

it('keeps the previous subject when a reply has none', function () {
    Artisan::call('reply:create-company', ['name' => 'West Quay']);
    Artisan::call('reply:create-contact', [
        'company' => 'West Quay',
        'name' => 'Bea Quay',
        'email' => 'bea@west.example',
    ]);
    Artisan::call('reply:log-inbound', [
        'company' => 'West Quay',
        'email' => 'bea@west.example',
        'receipt' => '2026-10-16 11:23',
        'source' => 'mail client',
        'summary' => 'Adam confirmed the first stage.',
        'client' => 'You are through to first stage.',
        '--subject' => 'Update',
    ]);
    $inbound = json_decode(Artisan::output(), true)['id'];

    expect(Artisan::call('reply:mark-sent', [
        'inbound' => $inbound,
        'sent' => '2026-10-16 18:31',
        'reply' => 'Thanks for the update.',
    ]))->toBe(0);

    $closed = app(ListClosedThreads::class)();

    expect($closed)->toHaveCount(1)
        ->and($closed[0]->replies[0]['subject'])->toBe('Update');

    expect(Artisan::call('reply:mark-sent', [
        'inbound' => $inbound,
        'sent' => '2026-10-23 10:07',
        'reply' => 'Thanks again.',
        '--subject' => 'Re: Update',
    ]))->toBe(0);

    expect(Artisan::call('reply:mark-sent', [
        'inbound' => $inbound,
        'sent' => '2026-10-26 19:30',
        'reply' => 'The case study is up.',
    ]))->toBe(0);

    $replies = app(ListClosedThreads::class)()[0]->replies;

    expect($replies)->toHaveCount(3)
        ->and($replies[1]['subject'])->toBe('Re: Update')
        ->and($replies[2]['subject'])->toBe('Re: Update');
});

it('records a reply with no subject when the client email has none', function () {
    Artisan::call('reply:create-company', ['name' => 'West Quay']);
    Artisan::call('reply:create-contact', [
        'company' => 'West Quay',
        'name' => 'Bea Quay',
        'email' => 'bea@west.example',
    ]);
    Artisan::call('reply:log-inbound', [
        'company' => 'West Quay',
        'email' => 'bea@west.example',
        'receipt' => '2026-10-20 10:00',
        'source' => 'mail client',
        'summary' => 'Which PHP to use',
        'client' => 'Which loaf for Friday.',
    ]);
    $inbound = json_decode(Artisan::output(), true)['id'];

    expect(Artisan::call('reply:mark-sent', [
        'inbound' => $inbound,
        'sent' => '2026-10-20 16:04',
        'reply' => 'Use 8.3.',
    ]))->toBe(0);

    $sent = (string) collect(replyDeskNoteBodies())->first(fn (string $body): bool => str_contains($body, 'Clock: sent'));

    expect($sent)->not->toContain('Subject:')
        ->and(app(ListClosedThreads::class)())->toHaveCount(1);
});
