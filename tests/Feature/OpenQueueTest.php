<?php

use App\Clock\ReceiptSource;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\MarkReplySent;
use App\Desk\Queue\ListOpenReplies;
use App\Filament\Resources\Inbounds\InboundResource;
use App\Filament\Resources\Inbounds\Pages\ListOpenQueue;
use App\Filament\Schemas\ReplyInfolist;
use App\Filament\Widgets\OpenQueueOverview;
use App\Mail\ClientReply;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(london('2026-10-23 12:00'));
    $this->actingAs(User::factory()->create());
    replyDeskParty('company', '10', 'Northwharf Bakery');
    replyDeskParty('contact', '20', 'Ada North', 'ada@northwharf.example', '10');
    replyDeskParty('company', '11', 'Harbour Cafe');
    replyDeskParty('contact', '21', 'Ned Harbour', 'ned@harbour.example', '11');
});

it('shows an open reply on the queue', function () {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(ListOpenQueue::class)
        ->assertCanSeeTableRecords(['1'])
        ->assertSee('Northwharf Bakery / Ada North')
        ->assertSeeHtml('fi-btn')
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

    Livewire::test(ListOpenQueue::class)
        ->assertCanSeeTableRecords(['1', '2'])
        ->filterTable('overdue', true)
        ->assertCanSeeTableRecords(['1'])
        ->assertCanNotSeeTableRecords(['2']);

    expect(collect(app(ListOpenReplies::class)(london('2026-10-23 12:00'), true))->map->id->all())
        ->toBe(['1']);
});

it('opens the clock note', function () {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(ListOpenQueue::class)
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

it('shows the filed client email in the note', function () {
    $states = array_map(
        fn ($entry) => (string) $entry->getState(),
        ReplyInfolist::forRecord([
            'client' => 'Northwharf Bakery / Ada North',
            'contact_email' => 'ada@northwharf.example',
            'receipt_source' => 'Mail client',
            'inbound' => '2026-10-20 10:00',
            'clock_start' => '2026-10-20 10:00',
            'reply_due' => '2026-10-21 10:00',
            'id' => '1',
            'summary' => 'Which PHP to use',
            'body' => "Clock: inbound\nReceipt: 2026-10-20 10:00 Europe/London\nReceipt source: mail client\nSummary: Which PHP to use\n\nClient email:\nWhich loaf for Friday.",
        ]),
    );

    expect($states)->toContain('Which loaf for Friday.');
});

it('marks a reply sent from the queue', function () {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(ListOpenQueue::class)
        ->callAction(TestAction::make('markSent')->table('1'), [
            'sent' => '2026-10-20 16:04:00',
            'subject' => 'PHP on the new server',
            'reply' => "Use 8.3 on the new box.\n\nMike",
        ])
        ->assertCanNotSeeTableRecords(['1'])
        ->assertSee('No open replies');

    expect(collect(replyDeskNoteBodies())->first(fn (string $body): bool => str_contains($body, 'Clock: sent')))
        ->toContain("Use 8.3 on the new box.\n\nMike");
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
        ->assertSee('1')
        ->assertSee('Wed 21 Oct 10:00');
});

it('shows none due when the queue is empty', function () {
    Livewire::test(OpenQueueOverview::class)
        ->assertSee('0')
        ->assertSee('None');
});

it('offers only the contacts on the chosen company', function () {
    Livewire::test(ListOpenQueue::class)
        ->mountAction('logInbound')
        ->assertFormFieldExists('company_id')
        ->assertFormFieldExists('contact_id')
        ->assertFormFieldExists('receipt_source')
        ->assertMountedActionModalSee('Mail client')
        ->assertMountedActionModalSee('Logged')
        ->assertMountedActionModalSee('Northwharf Bakery')
        ->assertMountedActionModalSee('Harbour Cafe')
        ->setActionData(['company_id' => '10'])
        ->assertMountedActionModalSee('ada@northwharf.example')
        ->assertMountedActionModalDontSee('ned@harbour.example');
});

it('logs an inbound from the queue', function () {
    Livewire::test(ListOpenQueue::class)
        ->callAction('logInbound', [
            'company_id' => '10',
            'contact_id' => '20',
            'receipt' => '2026-10-23 11:00:00',
            'receipt_source' => 'mail client',
            'subject' => 'The form can come off',
            'body' => "The form can come off the front.\nCan you look today?",
        ])
        ->assertNotified('Inbound logged')
        ->assertSee('Northwharf Bakery / Ada North')
        ->assertSee('The form can come off');

    $note = replyDeskNoteBodies()[0];

    expect($note)->toContain('Subject: The form can come off')
        ->and($note)->toContain("Client email:\nThe form can come off the front.\nCan you look today?")
        ->and($note)->not->toContain('Summary:');
});

it('keeps a pasted body on the logged email', function () {
    $body = "Hello Ada,\n\n    The loaf is  wholemeal.";

    Livewire::test(ListOpenQueue::class)
        ->callAction('logInbound', [
            'company_id' => '10',
            'contact_id' => '20',
            'receipt' => '2026-10-23 11:00:00',
            'receipt_source' => 'mail client',
            'subject' => 'The loaf',
            'body' => $body,
        ])
        ->assertNotified('Inbound logged');

    expect(replyDeskNoteBodies()[0])->toContain("Client email:\n".$body);

    Livewire::test(ListOpenQueue::class)
        ->mountAction(TestAction::make('view')->table('1'))
        ->assertMountedActionModalSee($body)
        ->assertMountedActionModalSee('white-space: pre-wrap');
});

it('shows the earlier thread on the note and the draft', function () {
    $closed = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
        'Which loaf for Friday.',
    );

    app(MarkReplySent::class)($closed, london('2026-10-20 16:04'), 'PHP on the new server', "Use 8.3 on the new box.\n\nMike");

    $open = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-21 09:00'),
        ReceiptSource::MailClient,
        'The form can come off',
    );

    Livewire::test(ListOpenQueue::class)
        ->mountAction(TestAction::make('view')->table($open))
        ->assertMountedActionModalSee('Earlier on this client')
        ->assertMountedActionModalSee('Which loaf for Friday.')
        ->assertMountedActionModalSee('Use 8.3 on the new box.');

    Livewire::test(ListOpenQueue::class)
        ->mountAction(TestAction::make('saveDraft')->table($open))
        ->assertMountedActionModalSee('Which loaf for Friday.')
        ->assertMountedActionModalSee('Use 8.3 on the new box.')
        ->assertFormFieldExists('subject')
        ->assertFormFieldExists('body');
});

it('requires the pasted reply before marking sent', function () {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(ListOpenQueue::class)
        ->mountAction(TestAction::make('markSent')->table('1'))
        ->assertFormFieldExists('sent')
        ->assertFormFieldExists('subject')
        ->assertFormFieldExists('reply')
        ->callMountedAction()
        ->assertHasFormErrors([
            'subject' => 'required',
            'reply' => 'required',
        ])
        ->assertCanSeeTableRecords(['1']);

    expect(collect(replyDeskNoteBodies())->filter(fn (string $body): bool => str_contains($body, 'Clock: sent')))->toHaveCount(0);
});

it('keeps a signed-out visitor off an inbound', function () {
    auth()->logout();

    $this->get('/')->assertRedirect('/login');
    $this->get('/history')->assertRedirect('/login');
    $this->get(InboundResource::getUrl('view', ['record' => '1']))->assertRedirect('/login');
});

it('sends the reply from the queue', function () {
    Mail::fake();

    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    Livewire::test(ListOpenQueue::class)
        ->assertActionExists(TestAction::make('send')->table('1'))
        ->callAction(TestAction::make('send')->table('1'), [
            'subject' => 'PHP on the new server',
            'body' => 'Use 8.3 on the new box.',
        ])
        ->assertCanNotSeeTableRecords(['1'])
        ->assertSee('No open replies');

    Mail::assertSent(ClientReply::class, fn (ClientReply $mail): bool => $mail->hasTo('ada@northwharf.example')
        && $mail->subjectLine === 'PHP on the new server');
});
