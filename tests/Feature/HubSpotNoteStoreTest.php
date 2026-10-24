<?php

use App\Clock\ClockNote;
use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\DeskException;
use App\Desk\HubSpotNoteStore;
use App\Desk\ListOpenReplies;
use App\Desk\LogInbound;
use App\Desk\StoredNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'reply-desk.portal_id' => '145255358',
        'reply-desk.token' => 'pat-test-token',
        'reply-desk.api_base' => 'https://api.hubapi.com',
    ]);

    Http::preventStrayRequests();
});

it('stops before writing when the portal id does not match', function () {
    Http::fake([
        'https://api.hubapi.com/account-info/v3/details' => Http::response(['portalId' => 999]),
    ]);

    expect(fn () => (new LogInbound(app(HubSpotNoteStore::class)))(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::Logged,
        'Which PHP to use',
    ))->toThrow(DeskException::class, 'does not match');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/crm/'));
});

it('writes an inbound note when the portal matches one company and one contact', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, 'account-info')) {
            return Http::response(['portalId' => 145255358]);
        }

        if (str_ends_with($url, '/companies/search')) {
            return Http::response(['results' => [[
                'id' => '10',
                'properties' => ['name' => 'Northwharf Bakery'],
            ]]]);
        }

        if (str_ends_with($url, '/contacts/search')) {
            return Http::response(['results' => [[
                'id' => '20',
                'properties' => ['email' => 'ada@northwharf.example', 'firstname' => 'Ada', 'lastname' => 'North'],
            ]]]);
        }

        if ($request->method() === 'POST' && str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/notes')) {
            return Http::response(['id' => '55'], 201);
        }

        if ($request->method() === 'PUT' && str_contains($url, '/associations/default/')) {
            return Http::response('', 204);
        }

        if (str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/companies/batch/read')) {
            return Http::response(['results' => [[
                'id' => '10',
                'properties' => ['name' => 'Northwharf Bakery'],
            ]]]);
        }

        if (str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/contacts/batch/read')) {
            return Http::response(['results' => [[
                'id' => '20',
                'properties' => ['email' => 'ada@northwharf.example', 'firstname' => 'Ada', 'lastname' => 'North'],
            ]]]);
        }

        return Http::response('unexpected '.$url, 500);
    });

    $id = (new LogInbound(app(HubSpotNoteStore::class)))(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    expect($id)->toBe('55');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/note/55/associations/default/company/10'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/note/55/associations/default/contact/20'));
});

it('uses the one company whose name contains every word', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, 'account-info')) {
            return Http::response(['portalId' => 145255358]);
        }

        if (str_ends_with($url, '/companies/search')) {
            $operator = $request->data()['filterGroups'][0]['filters'][0]['operator'] ?? '';

            if ($operator === 'EQ') {
                return Http::response(['results' => []]);
            }

            return Http::response(['results' => [[
                'id' => '10',
                'properties' => ['name' => 'V8 Hosting Limited'],
            ]]]);
        }

        if (str_ends_with($url, '/contacts/search')) {
            return Http::response(['results' => [[
                'id' => '20',
                'properties' => ['email' => 'lawrence@v8hosting.uk', 'firstname' => 'Lawrence', 'lastname' => 'Farr'],
            ]]]);
        }

        if ($request->method() === 'POST' && str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/notes')) {
            return Http::response(['id' => '55'], 201);
        }

        if ($request->method() === 'PUT') {
            return Http::response('', 204);
        }

        if (str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/companies/batch/read')) {
            return Http::response(['results' => [[
                'id' => '10',
                'properties' => ['name' => 'V8 Hosting Limited'],
            ]]]);
        }

        if (str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/contacts/batch/read')) {
            return Http::response(['results' => [[
                'id' => '20',
                'properties' => ['email' => 'lawrence@v8hosting.uk', 'firstname' => 'Lawrence', 'lastname' => 'Farr'],
            ]]]);
        }

        return Http::response('unexpected '.$url, 500);
    });

    $id = (new LogInbound(app(HubSpotNoteStore::class)))(
        'V8 Hosting',
        'lawrence@v8hosting.uk',
        london('2026-10-22 10:42'),
        ReceiptSource::Logged,
        'Which PHP to use',
    );

    expect($id)->toBe('55');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/note/55/associations/default/company/10'));
});

it('stops when more than one company matches on the portal', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'account-info')) {
            return Http::response(['portalId' => 145255358]);
        }

        if (str_ends_with($request->url(), '/companies/search')) {
            return Http::response(['results' => [
                ['id' => '10', 'properties' => ['name' => 'Northwharf Bakery']],
                ['id' => '11', 'properties' => ['name' => 'Northwharf Bakery']],
            ]]);
        }

        return Http::response('unexpected', 500);
    });

    expect(fn () => (new LogInbound(app(HubSpotNoteStore::class)))(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::Logged,
        'Which PHP to use',
    ))->toThrow(DeskException::class, 'More than one company matched (10, 11)');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/notes'));
});

it('lists an open clock note from recorded HubSpot responses', function () {
    $body = ClockNote::inbound(london('2026-10-20 10:00'), ReceiptSource::Logged, 'Which PHP to use');

    Http::fake(function (Request $request) use ($body) {
        $url = $request->url();

        if (str_contains($url, 'account-info')) {
            return Http::response(['portalId' => 145255358]);
        }

        if (str_ends_with($url, '/notes/search')) {
            return Http::response(['results' => [[
                'id' => '55',
                'properties' => ['hs_note_body' => $body],
            ]]]);
        }

        if (str_contains($url, '/notes/companies/batch/read')) {
            return Http::response(['results' => [[
                'from' => ['id' => '55'],
                'to' => [['toObjectId' => 10]],
            ]]]);
        }

        if (str_contains($url, '/notes/contacts/batch/read')) {
            return Http::response(['results' => [[
                'from' => ['id' => '55'],
                'to' => [['toObjectId' => 20]],
            ]]]);
        }

        if (str_ends_with($url, '/companies/batch/read')) {
            return Http::response(['results' => [[
                'id' => '10',
                'properties' => ['name' => 'Northwharf Bakery'],
            ]]]);
        }

        if (str_ends_with($url, '/contacts/batch/read')) {
            return Http::response(['results' => [[
                'id' => '20',
                'properties' => ['email' => 'ada@northwharf.example', 'firstname' => 'Ada', 'lastname' => 'North'],
            ]]]);
        }

        return Http::response('unexpected '.$url, 500);
    });

    $open = (new ListOpenReplies(app(HubSpotNoteStore::class), app(ReplyClock::class)))(london('2026-10-20 12:00'));

    expect($open)->toHaveCount(1)
        ->and($open[0]->client)->toBe('Northwharf Bakery / Ada North')
        ->and($open[0]->replyDue->format('Y-m-d H:i'))->toBe('2026-10-21 10:00');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/notes/'));
});

it('serves the queue from cache for the next minute', function () {
    $body = ClockNote::inbound(london('2026-10-20 10:00'), ReceiptSource::Logged, 'Which PHP to use');

    Http::fake(function (Request $request) use ($body) {
        $url = $request->url();

        if (str_contains($url, 'account-info')) {
            return Http::response(['portalId' => 145255358]);
        }

        if (str_ends_with($url, '/notes/search')) {
            return Http::response(['results' => [[
                'id' => '55',
                'properties' => ['hs_note_body' => $body],
            ]]]);
        }

        if (str_contains($url, '/notes/companies/batch/read')) {
            return Http::response(['results' => [[
                'from' => ['id' => '55'],
                'to' => [['toObjectId' => 10]],
            ]]]);
        }

        if (str_contains($url, '/notes/contacts/batch/read')) {
            return Http::response(['results' => [[
                'from' => ['id' => '55'],
                'to' => [['toObjectId' => 20]],
            ]]]);
        }

        if (str_ends_with($url, '/companies/batch/read')) {
            return Http::response(['results' => [[
                'id' => '10',
                'properties' => ['name' => 'Northwharf Bakery'],
            ]]]);
        }

        if (str_ends_with($url, '/contacts/batch/read')) {
            return Http::response(['results' => [[
                'id' => '20',
                'properties' => ['email' => 'ada@northwharf.example', 'firstname' => 'Ada', 'lastname' => 'North'],
            ]]]);
        }

        if ($request->method() === 'POST' && str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/notes')) {
            return Http::response(['id' => '56'], 201);
        }

        if ($request->method() === 'PUT') {
            return Http::response('', 204);
        }

        return Http::response('unexpected '.$url, 500);
    });

    Cache::put('reply-desk.notes.145255358', [
        new StoredNote('9', 'stale', '1', 'Old', '2', 'Old', 'old@example.com'),
    ], 60);

    $searches = fn (): int => collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/notes/search'))
        ->count();

    $notes = app(HubSpotNoteStore::class)->notes();

    expect($notes)->toHaveCount(1)
        ->and($notes[0]->id)->toBe('55')
        ->and($searches())->toBe(1)
        ->and(Cache::get('reply-desk.notes.145255358')[0])->toBeArray()
        ->and(Cache::get('reply-desk.notes.145255358')[0]['id'])->toBe('55');

    expect(app(HubSpotNoteStore::class)->notes())->toHaveCount(1)
        ->and($searches())->toBe(1);

    app(HubSpotNoteStore::class)->writeNote(
        ClockNote::sent('55', london('2026-10-20 11:00'), 'Re: Which PHP to use'),
        '10',
        '20',
    );

    $afterWrite = app(HubSpotNoteStore::class)->notes();

    expect($afterWrite)->toHaveCount(2)
        ->and(collect($afterWrite)->pluck('id')->all())->toContain('56')
        ->and($searches())->toBe(2)
        ->and(collect(Cache::get('reply-desk.pending.145255358'))->pluck('id')->all())->toContain('56');
});

it('retries once the ten second limit has cleared', function () {
    $calls = 0;

    Http::fake(function (Request $request) use (&$calls) {
        if (! str_contains($request->url(), 'account-info')) {
            return Http::response('unexpected', 500);
        }

        $calls++;

        if ($calls === 1) {
            return Http::response([
                'status' => 'error',
                'message' => 'You have reached your ten_secondly_rolling limit.',
            ], 429, ['Retry-After' => '0']);
        }

        return Http::response(['portalId' => 145255358]);
    });

    app(HubSpotNoteStore::class)->assertPortal();

    expect($calls)->toBe(2);
});

it('reads associations for every note in two batches', function () {
    $first = ClockNote::inbound(london('2026-10-20 10:00'), ReceiptSource::Logged, 'Which PHP to use');
    $second = ClockNote::inbound(london('2026-10-21 09:00'), ReceiptSource::MailClient, 'The form can come off');

    fakeHubSpotClockNotes([
        ['id' => '55', 'properties' => ['hs_note_body' => $first]],
        ['id' => '56', 'properties' => ['hs_note_body' => $second]],
    ]);

    expect(app(HubSpotNoteStore::class)->notes())->toHaveCount(2);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/notes/'));

    Http::assertSent(function (Request $request): bool {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

        if (! str_contains($path, '/notes/companies/batch/read')) {
            return false;
        }

        $ids = array_column($request->data()['inputs'] ?? [], 'id');
        sort($ids);

        return $ids === ['55', '56'];
    });
});

it('retries the note search when HubSpot is rate limited', function () {
    $body = ClockNote::inbound(london('2026-10-20 10:00'), ReceiptSource::Logged, 'Which PHP to use');
    $searches = 0;

    Http::fake(function (Request $request) use ($body, &$searches) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

        if (str_contains($path, 'account-info')) {
            return Http::response(['portalId' => 145255358]);
        }

        if (str_ends_with($path, '/notes/search')) {
            $searches++;

            if ($searches === 1) {
                return Http::response([
                    'status' => 'error',
                    'message' => 'You have reached your ten_secondly_rolling limit.',
                ], 429, ['Retry-After' => '0']);
            }

            return Http::response(['results' => [[
                'id' => '55',
                'properties' => ['hs_note_body' => $body],
            ]]]);
        }

        if (str_contains($path, '/notes/companies/batch/read')) {
            return Http::response(['results' => [[
                'from' => ['id' => '55'],
                'to' => [['toObjectId' => 10]],
            ]]]);
        }

        if (str_contains($path, '/notes/contacts/batch/read')) {
            return Http::response(['results' => [[
                'from' => ['id' => '55'],
                'to' => [['toObjectId' => 20]],
            ]]]);
        }

        if (str_ends_with($path, '/companies/batch/read')) {
            return Http::response(['results' => [[
                'id' => '10',
                'properties' => ['name' => 'Northwharf Bakery'],
            ]]]);
        }

        if (str_ends_with($path, '/contacts/batch/read')) {
            return Http::response(['results' => [[
                'id' => '20',
                'properties' => ['email' => 'ada@northwharf.example', 'firstname' => 'Ada', 'lastname' => 'North'],
            ]]]);
        }

        return Http::response('unexpected '.$path, 500);
    });

    $open = (new ListOpenReplies(app(HubSpotNoteStore::class), app(ReplyClock::class)))(london('2026-10-20 12:00'));

    expect($searches)->toBe(2)
        ->and($open)->toHaveCount(1)
        ->and($open[0]->summary)->toBe('Which PHP to use');
});

it('lists the queue from a serialized cache', function () {
    config([
        'cache.default' => 'note-cache',
        'cache.stores.note-cache' => [
            'driver' => 'array',
            'serialize' => true,
        ],
        'cache.serializable_classes' => false,
    ]);

    $body = ClockNote::inbound(london('2026-10-20 10:00'), ReceiptSource::Logged, 'Which PHP to use');

    fakeHubSpotClockNotes([
        ['id' => '55', 'properties' => ['hs_note_body' => $body]],
    ]);

    Cache::put('reply-desk.notes.145255358', [
        new StoredNote('9', $body, '10', 'Stale Bakery', '20', 'Ada North', 'ada@northwharf.example'),
    ], 60);

    $searches = fn (): int => collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/notes/search'))
        ->count();

    expect(app(HubSpotNoteStore::class)->notes()[0]->companyName)->toBe('Northwharf Bakery')
        ->and($searches())->toBe(1);

    $open = (new ListOpenReplies(app(HubSpotNoteStore::class), app(ReplyClock::class)))(london('2026-10-20 12:00'));

    expect($open)->toHaveCount(1)
        ->and($open[0]->summary)->toBe('Which PHP to use')
        ->and($open[0]->client)->toBe('Northwharf Bakery / Ada North')
        ->and($searches())->toBe(1);
});

it('caches companies and the contacts on them', function () {
    config([
        'cache.default' => 'note-cache',
        'cache.stores.note-cache' => [
            'driver' => 'array',
            'serialize' => true,
        ],
        'cache.serializable_classes' => false,
    ]);

    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

        if (str_contains($path, 'account-info')) {
            return Http::response(['portalId' => 145255358]);
        }

        if ($request->method() === 'GET' && str_ends_with($path, '/companies')) {
            return Http::response(['results' => [
                ['id' => '11', 'properties' => ['name' => 'Harbour Cafe']],
                ['id' => '10', 'properties' => ['name' => 'Northwharf Bakery']],
            ]]);
        }

        if (str_contains($path, '/companies/contacts/batch/read')) {
            return Http::response(['results' => [
                ['from' => ['id' => '10'], 'to' => [['toObjectId' => 20], ['toObjectId' => 22]]],
                ['from' => ['id' => '11'], 'to' => [['toObjectId' => 21]]],
            ]]);
        }

        if (str_ends_with($path, '/contacts/batch/read')) {
            return Http::response(['results' => [
                ['id' => '20', 'properties' => ['email' => 'ada@northwharf.example', 'firstname' => 'Ada', 'lastname' => 'North']],
                ['id' => '21', 'properties' => ['email' => 'ned@harbour.example', 'firstname' => 'Ned', 'lastname' => 'Shore']],
                ['id' => '22', 'properties' => ['email' => '', 'firstname' => 'No', 'lastname' => 'Address']],
            ]]);
        }

        return Http::response('unexpected '.$path, 500);
    });

    $reads = fn (): int => collect(Http::recorded())
        ->filter(fn (array $pair): bool => $pair[0]->method() === 'GET'
            && str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH) ?: '', '/companies'))
        ->count();

    $directory = app(HubSpotNoteStore::class)->directory();

    expect($directory)->toHaveCount(2)
        ->and($directory[0]['name'])->toBe('Harbour Cafe')
        ->and($directory[0]['contacts'][0]['email'])->toBe('ned@harbour.example')
        ->and($directory[1]['contacts'])->toHaveCount(1)
        ->and($directory[1]['contacts'][0]['name'])->toBe('Ada North')
        ->and($reads())->toBe(1)
        ->and(Cache::get('reply-desk.directory.145255358')[0])->toBeArray();

    expect(app(HubSpotNoteStore::class)->directory()[1]['contacts'][0]['email'])->toBe('ada@northwharf.example')
        ->and($reads())->toBe(1);
});

it('redacts the access token from a HubSpot error', function () {
    Http::fake([
        'https://api.hubapi.com/account-info/v3/details' => Http::response('token pat-test-token was rejected', 401),
    ]);

    expect(fn () => app(HubSpotNoteStore::class)->assertPortal())
        ->toThrow(DeskException::class, '[redacted]');

    try {
        app(HubSpotNoteStore::class)->assertPortal();
    } catch (DeskException $exception) {
        expect($exception->getMessage())->not->toContain('pat-test-token');
    }
});

it('keeps a written note on the next read until search returns it', function () {
    $inbound = ClockNote::inbound(london('2026-10-20 10:00'), ReceiptSource::Logged, 'Which PHP to use');
    $sent = ClockNote::sent('55', london('2026-10-20 11:00'), 'Re: Which PHP to use', 'Use 8.3.');
    $results = [[
        'id' => '55',
        'properties' => ['hs_note_body' => $inbound],
    ]];

    Cache::put('reply-desk.directory.145255358', [[
        'id' => '10',
        'name' => 'Northwharf Bakery',
        'contacts' => [[
            'id' => '20',
            'name' => 'Ada North',
            'email' => 'ada@northwharf.example',
        ]],
    ]], 3600);

    Http::fake(function (Request $request) use (&$results) {
        return fakeHubSpotNoteHttp($request, $results, '56');
    });

    app(HubSpotNoteStore::class)->writeNote($sent, '10', '20');

    $missed = app(HubSpotNoteStore::class)->notes();
    $written = collect($missed)->firstWhere('id', '56');

    expect($missed)->toHaveCount(2)
        ->and($written)->toBeInstanceOf(StoredNote::class);

    expect($written->companyName)->toBe('Northwharf Bakery')
        ->and($written->contactName)->toBe('Ada North')
        ->and($written->body)->toBe($sent)
        ->and(collect(Cache::get('reply-desk.pending.145255358'))->pluck('id')->all())->toBe(['56']);

    expect(app(HubSpotNoteStore::class)->notes())->toHaveCount(2)
        ->and(collect(Cache::get('reply-desk.pending.145255358'))->pluck('id')->all())->toBe(['56']);

    Cache::forget('reply-desk.notes.145255358');
    $results[] = [
        'id' => '56',
        'properties' => ['hs_note_body' => $sent],
    ];

    $caughtUp = app(HubSpotNoteStore::class)->notes();

    expect(collect($caughtUp)->pluck('id')->all())->toContain('56')
        ->and(Cache::get('reply-desk.pending.145255358'))->toBeNull();
});

it('keeps a replaced body until search returns that body', function () {
    $original = ClockNote::inbound(london('2026-10-20 10:00'), ReceiptSource::Logged, 'Which PHP to use');
    $replaced = ClockNote::withMail($original, 'Which loaf for Friday.');
    $results = [[
        'id' => '55',
        'properties' => ['hs_note_body' => $original],
    ]];

    Http::fake(function (Request $request) use (&$results) {
        return fakeHubSpotNoteHttp($request, $results, '55');
    });

    expect(app(HubSpotNoteStore::class)->notes()[0]->body)->toBe($original);

    app(HubSpotNoteStore::class)->replaceNote('55', $replaced);

    $notes = app(HubSpotNoteStore::class)->notes();

    expect($notes)->toHaveCount(1)
        ->and($notes[0]->body)->toBe($replaced)
        ->and($notes[0]->companyName)->toBe('Northwharf Bakery')
        ->and(collect(Cache::get('reply-desk.pending.145255358'))->pluck('id')->all())->toBe(['55']);

    Cache::forget('reply-desk.notes.145255358');
    $results = [[
        'id' => '55',
        'properties' => ['hs_note_body' => $replaced],
    ]];

    expect(app(HubSpotNoteStore::class)->notes()[0]->body)->toBe($replaced)
        ->and(Cache::get('reply-desk.pending.145255358'))->toBeNull();
});

it('drops a pending note after fifteen minutes', function () {
    $inbound = ClockNote::inbound(london('2026-10-20 10:00'), ReceiptSource::Logged, 'Which PHP to use');
    $sent = ClockNote::sent('55', london('2026-10-20 11:00'), 'Re: Which PHP to use');
    $results = [[
        'id' => '55',
        'properties' => ['hs_note_body' => $inbound],
    ]];

    Http::fake(function (Request $request) use (&$results) {
        return fakeHubSpotNoteHttp($request, $results, '56');
    });

    app(HubSpotNoteStore::class)->writeNote($sent, '10', '20');
    $this->travel(16)->minutes();
    Cache::forget('reply-desk.notes.145255358');

    expect(collect(app(HubSpotNoteStore::class)->notes())->pluck('id')->all())->toBe(['55']);
});

/**
 * @param  list<array{id: string, properties: array{hs_note_body: string}}>  $results
 */
function fakeHubSpotNoteHttp(Request $request, array $results, string $createdId): mixed
{
    $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

    if (str_contains($path, 'account-info')) {
        return Http::response(['portalId' => 145255358]);
    }

    if (str_ends_with($path, '/notes/search')) {
        return Http::response(['results' => $results]);
    }

    if (str_contains($path, '/notes/companies/batch/read')) {
        return Http::response(['results' => array_map(fn (array $note): array => [
            'from' => ['id' => $note['id']],
            'to' => [['toObjectId' => 10]],
        ], $results)]);
    }

    if (str_contains($path, '/notes/contacts/batch/read')) {
        return Http::response(['results' => array_map(fn (array $note): array => [
            'from' => ['id' => $note['id']],
            'to' => [['toObjectId' => 20]],
        ], $results)]);
    }

    if (str_ends_with($path, '/companies/batch/read')) {
        return Http::response(['results' => [[
            'id' => '10',
            'properties' => ['name' => 'Northwharf Bakery'],
        ]]]);
    }

    if (str_ends_with($path, '/contacts/batch/read')) {
        return Http::response(['results' => [[
            'id' => '20',
            'properties' => ['email' => 'ada@northwharf.example', 'firstname' => 'Ada', 'lastname' => 'North'],
        ]]]);
    }

    if ($request->method() === 'POST' && str_ends_with($path, '/notes')) {
        return Http::response(['id' => $createdId], 201);
    }

    if ($request->method() === 'PUT' || $request->method() === 'PATCH') {
        return Http::response('', 204);
    }

    return Http::response('unexpected '.$path, 500);
}

/**
 * @param  list<array{id: string, properties: array{hs_note_body: string}}>  $results
 */
function fakeHubSpotClockNotes(array $results): void
{
    Http::fake(function (Request $request) use ($results) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

        if (str_contains($path, 'account-info')) {
            return Http::response(['portalId' => 145255358]);
        }

        if (str_ends_with($path, '/notes/search')) {
            return Http::response(['results' => $results]);
        }

        if (str_contains($path, '/notes/companies/batch/read')) {
            return Http::response(['results' => array_map(fn (array $note): array => [
                'from' => ['id' => $note['id']],
                'to' => [['toObjectId' => 10]],
            ], $results)]);
        }

        if (str_contains($path, '/notes/contacts/batch/read')) {
            return Http::response(['results' => array_map(fn (array $note): array => [
                'from' => ['id' => $note['id']],
                'to' => [['toObjectId' => 20]],
            ], $results)]);
        }

        if (str_ends_with($path, '/companies/batch/read')) {
            return Http::response(['results' => [[
                'id' => '10',
                'properties' => ['name' => 'Northwharf Bakery'],
            ]]]);
        }

        if (str_ends_with($path, '/contacts/batch/read')) {
            return Http::response(['results' => [[
                'id' => '20',
                'properties' => ['email' => 'ada@northwharf.example', 'firstname' => 'Ada', 'lastname' => 'North'],
            ]]]);
        }

        return Http::response('unexpected '.$path, 500);
    });
}
