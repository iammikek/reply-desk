<?php

use App\Clock\ReceiptSource;
use App\Desk\LogInbound;
use App\Filament\Pages\OpenQueue;
use App\Filament\Schemas\ReplyInfolist;
use App\Filament\Widgets\OpenQueueOverview;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(london('2026-10-23 12:00'));
    $this->actingAs(User::factory()->create());
    replyDeskParty('company', '10', 'Northwharf Bakery');
    replyDeskParty('contact', '20', 'Ada North', 'ada@northwharf.example');
    replyDeskParty('company', '11', 'Harbour Cafe');
    replyDeskParty('contact', '21', 'Ned Harbour', 'ned@harbour.example');
});

it('shows an open reply on the queue', function () {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(OpenQueue::class)
        ->assertCanSeeTableRecords(['1'])
        ->assertSee('Northwharf Bakery / Ada North')
        ->assertSee('Which PHP to use')
        ->assertSee('2026-10-21 10:00');
});

it('hides a reply that is not overdue', function () {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::Logged,
        'Overdue piece',
    );
    app(LogInbound::class)(
        'Harbour Cafe',
        'ned@harbour.example',
        london('2026-10-23 09:00'),
        ReceiptSource::MailClient,
        'Still in time',
    );

    Livewire::test(OpenQueue::class)
        ->assertCanSeeTableRecords(['1', '2'])
        ->filterTable('overdue', true)
        ->assertCanSeeTableRecords(['1'])
        ->assertCanNotSeeTableRecords(['2']);
});

it('opens the clock note', function () {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(OpenQueue::class)
        ->mountAction(TestAction::make('view')->table('1'))
        ->assertSet('mountedActions.0.name', 'view')
        ->assertSet('mountedActions.0.context.recordKey', '1');

    $states = array_map(
        fn ($entry) => $entry->getState(),
        ReplyInfolist::forRecord([
            'client' => 'Northwharf Bakery / Ada North',
            'contact_email' => 'ada@northwharf.example',
            'receipt_source' => 'Mail client',
            'inbound' => '2026-10-20 10:00',
            'clock_start' => '2026-10-20 10:00',
            'reply_due' => '2026-10-21 10:00',
            'id' => '1',
            'summary' => 'Which PHP to use',
            'body' => "Clock: inbound\nReceipt: 2026-10-20 10:00 Europe/London",
        ]),
    );

    expect($states)->toContain('ada@northwharf.example')
        ->and($states)->toContain('Which PHP to use');
});

it('marks a reply sent from the queue', function () {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(OpenQueue::class)
        ->callAction(TestAction::make('markSent')->table('1'), [
            'sent' => '2026-10-20 16:04:00',
            'subject' => 'PHP on the new server',
        ])
        ->assertCanNotSeeTableRecords(['1'])
        ->assertSee('No open replies');
});

it('counts open and overdue replies', function () {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::Logged,
        'Overdue piece',
    );

    Livewire::test(OpenQueueOverview::class)
        ->assertSee('Open')
        ->assertSee('Overdue')
        ->assertSee('Next due');
});
