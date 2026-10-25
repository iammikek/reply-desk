<?php

use App\Clock\ReceiptSource;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\MarkReplySent;
use App\Filament\Pages\Client;
use App\Filament\Resources\Inbounds\Pages\ListHistory;
use App\Filament\Resources\Inbounds\Pages\ListOpenQueue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(london('2026-10-23 12:00'));
    $this->actingAs(User::factory()->create());
    replyDeskParty('company', '10', 'Northwharf Bakery');
    replyDeskParty('contact', '20', 'Ada North', 'ada@northwharf.example', '10');
    replyDeskParty('contact', '22', 'Bea North', 'bea@northwharf.example', '10');
    replyDeskParty('company', '11', 'Harbour Cafe');
    replyDeskParty('contact', '21', 'Ned Harbour', 'ned@harbour.example', '11');
});

it('opens one timeline for that company and contact', function () {
    $first = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
        'Which loaf for Friday.',
    );

    app(MarkReplySent::class)($first, london('2026-10-20 16:04'), 'PHP on the new server', 'Use 8.3 on the new box.');

    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-22 09:00'),
        ReceiptSource::MailClient,
        'The form can come off',
        'Can you look today?',
    );

    app(LogInbound::class)(
        'Northwharf Bakery',
        'bea@northwharf.example',
        london('2026-10-21 11:00'),
        ReceiptSource::MailClient,
        'Bea only',
        'This is for Bea.',
    );

    app(LogInbound::class)(
        'Harbour Cafe',
        'ned@harbour.example',
        london('2026-10-21 12:00'),
        ReceiptSource::MailClient,
        'The till tape',
        'The tape jammed.',
    );

    $url = Client::urlFor('10', '20');

    Livewire::test(ListHistory::class)
        ->assertSee($url, false)
        ->assertSee('Northwharf Bakery / Ada North');

    Livewire::test(ListOpenQueue::class)
        ->assertSee($url, false);

    $this->get($url)
        ->assertSuccessful()
        ->assertSee('Northwharf Bakery / Ada North')
        ->assertSee('ada@northwharf.example')
        ->assertSeeInOrder([
            '2026-10-20 10:00',
            'Which loaf for Friday.',
            '2026-10-20 16:04',
            'Use 8.3 on the new box.',
            '2026-10-22 09:00',
            'Can you look today?',
        ])
        ->assertDontSee('This is for Bea.')
        ->assertDontSee('The tape jammed.');
});

it('does not open a client with no correspondence', function () {
    $this->get(Client::urlFor('10', '99'))
        ->assertNotFound();
});

it('does not open a client page for a signed-out visitor', function () {
    auth()->logout();

    $this->get(Client::urlFor('10', '20'))
        ->assertRedirect();
});
