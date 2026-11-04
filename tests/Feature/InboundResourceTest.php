<?php

use App\Desk\Notes\HubSpotNoteStore;
use App\Desk\Notes\NoteStore;
use App\Filament\ClientThreadNav;
use App\Filament\Resources\Inbounds\Pages\ViewInbound;
use App\Models\Email;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('reads a HubSpot inbound without querying the local emails table', function (): void {
    config([
        'reply-desk.store' => 'hubspot',
        'reply-desk.portal_id' => '100000001',
    ]);

    Cache::put('reply-desk.notes.100000001', [
        [
            'id' => '455547014356',
            'body' => "Clock: inbound\nReceipt: 2026-10-22 10:00 Europe/London\nReceipt source: mail client\nSummary: Which PHP to use\n\nClient email:\nWhich loaf.",
            'companyId' => '455547014356',
            'companyName' => 'Spectra Stand-in',
            'contactId' => '81',
            'contactName' => 'Pat Example',
            'contactEmail' => 'pat@spectra.example',
            'attachmentName' => null,
            'attachmentId' => null,
        ],
        [
            'id' => '455547014357',
            'body' => "Clock: sent\nCloses: 455547014356\nSent: 2026-10-22 16:00 Europe/London\nSubject: PHP on the new server\n\nReply:\nThe wholemeal.",
            'companyId' => '455547014356',
            'companyName' => 'Spectra Stand-in',
            'contactId' => '81',
            'contactName' => 'Pat Example',
            'contactEmail' => 'pat@spectra.example',
            'attachmentName' => null,
            'attachmentId' => null,
        ],
    ], 60);

    $this->app->instance(NoteStore::class, $this->app->make(HubSpotNoteStore::class));
    $this->actingAs(User::factory()->create());

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ViewInbound::class, ['record' => '455547014356'])
        ->assertSee('Spectra Stand-in')
        ->assertSee('Which PHP to use')
        ->assertSee('PHP on the new server')
        ->assertSee('The wholemeal.')
        ->call('$refresh')
        ->assertSee('Which PHP to use');

    $tables = collect(DB::getQueryLog())->pluck('query')->implode(' ');

    expect($tables)->not->toContain('emails')
        ->and(Email::query()->where('summary', 'Which PHP to use')->exists())->toBeFalse();
});

it('shows earlier company correspondence on an open inbound with no closing reply', function (): void {
    config([
        'reply-desk.store' => 'hubspot',
        'reply-desk.portal_id' => '100000002',
    ]);

    Cache::put('reply-desk.notes.100000002', [
        [
            'id' => '1001',
            'body' => "Clock: inbound\nReceipt: 2026-10-20 10:00 Europe/London\nReceipt source: mail client\nSummary: Earlier question\n\nClient email:\nWhat about rye?",
            'companyId' => '2001',
            'companyName' => 'Baker Stand-in',
            'contactId' => '91',
            'contactName' => 'Sam Baker',
            'contactEmail' => 'sam@baker.example',
            'attachmentName' => null,
            'attachmentId' => null,
        ],
        [
            'id' => '1002',
            'body' => "Clock: sent\nCloses: 1001\nSent: 2026-10-20 16:00 Europe/London\nSubject: Re: Earlier question\n\nReply:\nRye works well.",
            'companyId' => '2001',
            'companyName' => 'Baker Stand-in',
            'contactId' => '91',
            'contactName' => 'Sam Baker',
            'contactEmail' => 'sam@baker.example',
            'attachmentName' => null,
            'attachmentId' => null,
        ],
        [
            'id' => '1003',
            'body' => "Clock: inbound\nReceipt: 2026-11-04 09:00 Europe/London\nReceipt source: mail client\nSummary: Follow-on\n\nClient email:\nAnd sourdough?",
            'companyId' => '2001',
            'companyName' => 'Baker Stand-in',
            'contactId' => '91',
            'contactName' => 'Sam Baker',
            'contactEmail' => 'sam@baker.example',
            'attachmentName' => null,
            'attachmentId' => null,
        ],
    ], 60);

    $this->app->instance(NoteStore::class, $this->app->make(HubSpotNoteStore::class));
    $this->actingAs(User::factory()->create());

    Livewire::test(ViewInbound::class, ['record' => '1003'])
        ->assertSee('Baker Stand-in')
        ->assertSee('Follow-on')
        ->assertSee('And sourdough?')
        ->assertSee('Earlier question')
        ->assertSee('Rye works well.')
        ->assertDontSee('No messages with this contact yet.');

    $url = ViewInbound::getUrl(['record' => '1003']);

    expect(ClientThreadNav::partyFromPath('/inbounds/1003'))
        ->toBe(['2001', '91', 'inbound', '1003']);

    $this->get($url)
        ->assertSuccessful()
        ->assertSee('Threads')
        ->assertSee('href="'.$url.'#thread-1001"', false)
        ->assertSee('href="'.$url.'#thread-1003"', false)
        ->assertSeeInOrder([
            'Threads',
            'Earlier question',
            'Follow-on',
        ]);
});
