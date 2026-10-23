<?php

use App\Clock\ClockNote;
use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\DeskException;
use App\Desk\HubSpotNoteStore;
use App\Desk\ListOpenReplies;
use App\Desk\LogInbound;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

        if (str_contains($url, '/notes/55')) {
            return Http::response([
                'id' => '55',
                'properties' => ['hs_note_body' => $body],
                'associations' => [
                    'companies' => ['results' => [['id' => '10']]],
                    'contacts' => ['results' => [['id' => '20']]],
                ],
            ]);
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
