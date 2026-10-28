<?php

use App\Desk\Notes\HubSpotNoteStore;
use App\Desk\Notes\NoteStore;
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
