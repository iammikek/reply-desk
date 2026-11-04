<?php

use App\Clock\ReceiptSource;
use App\Desk\Actions\InboundLogInput;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\MarkReplySent;
use App\Desk\Queue\ListClientCorrespondence;
use App\Filament\ClientThreadNav;
use App\Filament\Pages\Client;
use App\Filament\Resources\Inbounds\Pages\ListHistory;
use App\Filament\Resources\Inbounds\Pages\ListOpenQueue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedReplyDeskDirectory('2026-10-23 12:00');
    $this->actingAs(User::factory()->create());
    replyDeskParty('contact', '22', 'Bea North', 'bea@northwharf.example', '10');
    replyDeskParty('company', '11', 'Harbour Cafe');
    replyDeskParty('contact', '21', 'Ned Harbour', 'ned@harbour.example', '11');
});

it('opens one timeline for that company and contact', function (): void {
    $first = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-20 10:00'),
            ReceiptSource::MailClient,
            'Which PHP to use',
            'Which loaf for Friday.',
        )
    );

    app(MarkReplySent::class)($first, london('2026-10-20 16:04'), 'PHP on the new server', 'Use 8.3 on the new box.');

    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-22 09:00'),
            ReceiptSource::MailClient,
            'The form can come off',
            'Can you look today?',
        )
    );

    app(LogInbound::class)(
        'Northwharf Bakery',
        'bea@northwharf.example',
        new InboundLogInput(
            london('2026-10-21 11:00'),
            ReceiptSource::MailClient,
            'Bea only',
            'This is for Bea.',
        )
    );

    app(LogInbound::class)(
        'Harbour Cafe',
        'ned@harbour.example',
        new InboundLogInput(
            london('2026-10-21 12:00'),
            ReceiptSource::MailClient,
            'The till tape',
            'The tape jammed.',
        )
    );

    $url = Client::urlFor('10', '20');

    Livewire::test(ListHistory::class)
        ->assertSee($url, false)
        ->assertSee('Northwharf Bakery / Ada North');

    Livewire::test(ListOpenQueue::class)
        ->assertSee($url, false);

    $found = app(ListClientCorrespondence::class)('10', '20');
    $firstThreadId = $found->replies()[0]['id'];
    $secondThreadId = $found->replies()[1]['id'];

    $this->get($url)
        ->assertSuccessful()
        ->assertSee('Northwharf Bakery / Ada North')
        ->assertSee('ada@northwharf.example')
        ->assertSee('Replies')
        ->assertDontSee('Outreach')
        ->assertSee('Threads')
        ->assertSee('href="'.$url.'#thread-'.$firstThreadId.'"', false)
        ->assertSee('href="'.$url.'#thread-'.$secondThreadId.'"', false)
        ->assertSee('id="thread-'.$firstThreadId.'"', false)
        ->assertSeeInOrder([
            'Threads',
            'PHP on the new server',
            'The form can come off',
            'Started 2026-10-20 10:00',
            'PHP on the new server',
            'Which loaf for Friday.',
            '2026-10-20 16:04',
            'Use 8.3 on the new box.',
            'Started 2026-10-22 09:00',
            'The form can come off',
            'Can you look today?',
        ])
        ->assertDontSee('This is for Bea.')
        ->assertDontSee('The tape jammed.');
});

it('keeps outreach threads in their own section', function (): void {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-20 10:00'),
            ReceiptSource::MailClient,
            'Which PHP to use',
            'Which loaf for Friday.',
        )
    );

    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-21 09:00'),
            ReceiptSource::Logged,
            'Outreach started',
            '',
        )
    );

    $found = app(ListClientCorrespondence::class)('10', '20');

    expect($found)->not->toBeNull()
        ->and($found->replies())->toHaveCount(1)
        ->and($found->outreach())->toHaveCount(1)
        ->and($found->outreach()[0]['summary'])->toBe('Outreach started')
        ->and($found->outreach()[0]['started_at'])->toBe('2026-10-21 09:00');

    $this->get(Client::urlFor('10', '20'))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Replies',
            'Which PHP to use',
            'Outreach',
            'Started 2026-10-21 09:00',
        ])
        ->assertDontSee('>Outreach started</');
});

it('lists threads oldest first and keeps messages oldest at the top', function (): void {
    $older = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-18 09:00'),
            ReceiptSource::MailClient,
            'Older thread',
            'Started earlier.',
            subject: 'Friday loaf order',
        )
    );

    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-20 09:00'),
            ReceiptSource::MailClient,
            'Newer open thread',
            'Opened later.',
            subject: 'Till tape jammed',
        )
    );

    app(MarkReplySent::class)($older, london('2026-10-22 14:00'), 'Friday loaf order', 'Replied after the newer open.');

    $found = app(ListClientCorrespondence::class)('10', '20');

    expect($found->replies()[0]['subject'])->toBe('Friday loaf order')
        ->and($found->replies()[0]['started_at'])->toBe('2026-10-18 09:00')
        ->and($found->replies()[0]['latest_at'])->toBe('2026-10-22 14:00')
        ->and($found->replies()[1]['subject'])->toBe('Till tape jammed')
        ->and($found->replies()[1]['started_at'])->toBe('2026-10-20 09:00')
        ->and($found->replies()[0]['events'][0]['side'])->toBe('client')
        ->and($found->replies()[0]['events'][1]['side'])->toBe('owner');

    $this->get(Client::urlFor('10', '20'))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Started 2026-10-18 09:00',
            'Friday loaf order',
            'Started earlier.',
            'Replied after the newer open.',
            'Started 2026-10-20 09:00',
            'Till tape jammed',
            'Opened later.',
        ]);
});

it('keeps a LinkedIn back-and-forth as one conversation', function (): void {
    $first = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-11-02 12:39'),
            ReceiptSource::LinkedIn,
            'Asked for hourly or weekly rate',
            'What kind of hourly/weekly rate are you?',
        )
    );

    app(MarkReplySent::class)($first, london('2026-11-02 13:59'), 'LinkedIn', 'About £450–£500/day.');

    $second = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-11-02 19:42'),
            ReceiptSource::LinkedIn,
            'Thanks.',
            'Thanks Mike',
        )
    );

    app(MarkReplySent::class)($second, london('2026-11-02 19:43'), 'LinkedIn', 'Quickest role filled.');

    $third = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-11-02 19:52'),
            ReceiptSource::LinkedIn,
            'India rate £2k for 160 hours full stack.',
            '£2k - 160 hours Full Stack',
        )
    );

    app(MarkReplySent::class)($third, london('2026-11-02 19:54'), 'LinkedIn', 'Way cheaper than UK.');

    $found = app(ListClientCorrespondence::class)('10', '20');

    expect($found->replies())->toHaveCount(1)
        ->and($found->replies()[0]['started_at'])->toBe('2026-11-02 12:39')
        ->and($found->replies()[0]['latest_at'])->toBe('2026-11-02 19:54')
        ->and($found->replies()[0]['subject'])->toBe('LinkedIn')
        ->and($found->replies()[0]['status'])->toBe('replied')
        ->and(collect($found->replies()[0]['events'])->pluck('side')->all())->toBe([
            'client',
            'owner',
            'client',
            'owner',
            'client',
            'owner',
        ]);

    $this->get(Client::urlFor('10', '20'))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Started 2026-11-02 12:39',
            'LinkedIn',
            'What kind of hourly/weekly rate are you?',
            'About £450–£500/day.',
            'Thanks Mike',
            '£2k - 160 hours Full Stack',
            'Way cheaper than UK.',
        ])
        ->assertDontSee('Started 2026-11-02 19:42')
        ->assertDontSee('Started 2026-11-02 19:52');
});

it('shows one bubble when the same message was logged twice', function (): void {
    $first = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-26 16:17'),
            ReceiptSource::MailClient,
            'He will digest the note. The attached audit looks like another company.',
            "Thanks, Mike. I'll digest this. In the meantime, I think you've attached an audit for another company.\nBest wishes,\nJames",
            subject: 'Re: Technical-textiles.net',
        )
    );

    app(MarkReplySent::class)(
        $first,
        london('2026-10-26 16:54'),
        'Re: Technical-textiles.net',
        "Hi James\nGood spot.\n\nIts been a long day!\n\nTry this:\n\nBest regards\nMike",
    );

    $duplicate = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-26 16:17'),
            ReceiptSource::MailClient,
            'He will digest the note. He thinks the attachment is an audit for another company.',
            "Thanks, Mike. I'll digest this. In the meantime, I think you've attached an audit for another company.\nBest wishes,\nJames",
            subject: 'Re: Technical-textiles.net',
        )
    );

    app(MarkReplySent::class)(
        $duplicate,
        london('2026-10-26 16:54'),
        'Re: Technical-textiles.net',
        "Hi James\n\nGood spot.\n\nIts been a long day!\n\nTry this:\n\nBest regards\nMike",
    );

    $events = app(ListClientCorrespondence::class)('10', '20')->replies()[0]['events'];

    expect($events)->toHaveCount(2)
        ->and(collect($events)->where('side', 'client')->count())->toBe(1)
        ->and(collect($events)->where('side', 'owner')->count())->toBe(1)
        ->and($events[0]['body'])->toContain("I'll digest this")
        ->and($events[1]['body'])->toContain('Good spot');
});

it('merges mail that shares a subject into one conversation', function (): void {
    $first = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-20 10:00'),
            ReceiptSource::MailClient,
            'Which PHP to use',
            'Which loaf for Friday.',
            subject: 'PHP on the new server',
        )
    );

    app(MarkReplySent::class)($first, london('2026-10-20 16:04'), 'Re: PHP on the new server', 'Use 8.3.');

    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-21 09:00'),
            ReceiptSource::MailClient,
            'Form can come off',
            'Can you look today?',
            subject: 'Re: PHP on the new server',
        )
    );

    $found = app(ListClientCorrespondence::class)('10', '20');

    expect($found->replies())->toHaveCount(1)
        ->and($found->replies()[0]['started_at'])->toBe('2026-10-20 10:00')
        ->and($found->replies()[0]['subject'])->toBe('PHP on the new server')
        ->and($found->replies()[0]['status'])->toBe('open');

    $this->get(Client::urlFor('10', '20'))
        ->assertSuccessful()
        ->assertSee('PHP on the new server')
        ->assertDontSee('Re: PHP on the new server');
});

it('keeps one mail thread when outreach and replies share a conversation', function (): void {
    $outreach = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-22 09:50'),
            ReceiptSource::Logged,
            'Outreach started',
            '',
        )
    );

    app(MarkReplySent::class)(
        $outreach,
        london('2026-10-22 09:50'),
        'technical-textiles.net still on PHP 5.5.9',
        'Still on old PHP.',
    );

    $followUp = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-23 16:00'),
            ReceiptSource::Logged,
            'Follow-up sent',
            '',
        )
    );

    app(MarkReplySent::class)(
        $followUp,
        london('2026-10-23 16:00'),
        'Looks like I’ve got enough to boot the app but db looks like it’s from 2024 if the sql/intdrupal/ directory is anything to go by.',
        '',
    );

    $reply = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-23 07:58'),
            ReceiptSource::MailClient,
            'Asked for a site dump',
            'Can you send a dump?',
        )
    );

    app(MarkReplySent::class)(
        $reply,
        london('2026-10-23 07:58'),
        'Access to technical textiles site',
        'Here is the ask.',
    );

    $found = app(ListClientCorrespondence::class)('10', '20');

    $bodies = collect($found->replies()[0]['events'])->pluck('body')->all();

    expect($found->replies())->toHaveCount(1)
        ->and($found->outreach())->toHaveCount(0)
        ->and($found->replies()[0]['subject'])->toBe('Access to technical textiles site')
        ->and($found->replies()[0]['started_at'])->toBe('2026-10-22 09:50')
        ->and($bodies)->toContain('Still on old PHP.')
        ->and($bodies)->toContain('Can you send a dump?')
        ->and($bodies)->toContain(
            'Looks like I’ve got enough to boot the app but db looks like it’s from 2024 if the sql/intdrupal/ directory is anything to go by.',
        )
        ->and($bodies)->not->toContain('Outreach started')
        ->and($bodies)->not->toContain('Asked for a site dump')
        ->and($bodies)->not->toContain('technical-textiles.net still on PHP 5.5.9');
});

it('starts a new thread when the subject is clearly different', function (): void {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-20 10:00'),
            ReceiptSource::MailClient,
            'Loaf question',
            'Which loaf?',
            subject: 'Friday loaf order',
        )
    );

    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-21 10:00'),
            ReceiptSource::MailClient,
            'Till question',
            'Till stuck.',
            subject: 'Till tape jammed',
        )
    );

    $found = app(ListClientCorrespondence::class)('10', '20');

    expect($found->replies())->toHaveCount(2)
        ->and($found->replies()[0]['subject'])->toBe('Friday loaf order')
        ->and($found->replies()[1]['subject'])->toBe('Till tape jammed');
});

it('keeps thread nav available after a sidebar refresh on the client page', function (): void {
    $first = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        new InboundLogInput(
            london('2026-10-20 10:00'),
            ReceiptSource::MailClient,
            'Which PHP to use',
            'Which loaf for Friday.',
            subject: 'PHP on the new server',
        )
    );

    app(MarkReplySent::class)($first, london('2026-10-20 16:04'), 'PHP on the new server', 'Use 8.3.');

    $url = Client::urlFor('10', '20');
    $threadId = app(ListClientCorrespondence::class)('10', '20')->replies()[0]['id'];

    expect(ClientThreadNav::partyFromPath('/clients/10/20'))
        ->toBe(['10', '20', 'client', null]);

    // Sidebar Livewire refresh posts to Livewire, not the client route.
    app()->instance(
        'request',
        Request::create('/livewire/update', 'POST', server: [
            'HTTP_REFERER' => url($url),
        ]),
    );

    $items = ClientThreadNav::items();

    expect($items)->not->toBeEmpty()
        ->and($items[0]['label'])->toBe('PHP on the new server')
        ->and($items[0]['url'])->toEndWith('#thread-'.$threadId);
});

it('does not open a client with no correspondence', function (): void {
    $this->get(Client::urlFor('10', '99'))
        ->assertNotFound();
});

it('does not open a client page for a signed-out visitor', function (): void {
    auth()->logout();

    $this->get(Client::urlFor('10', '20'))
        ->assertRedirect();
});
