<?php

use App\Clock\ReceiptSource;
use App\Desk\DeskException;
use App\Desk\ListOpenReplies;
use App\Desk\LogInbound;
use App\Desk\SaveDraft;
use App\Desk\SendReply;
use App\Filament\Pages\OpenQueue;
use App\Mail\ClientReply;
use App\Models\Draft;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(london('2026-10-23 15:30'));
    replyDeskParty('company', '10', 'Northwharf Bakery');
    replyDeskParty('contact', '20', 'Ada North', 'ada@northwharf.example');
});

it('closes the row after the mail server accepts', function () {
    Mail::fake();

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3 on the new box.');

    app(SendReply::class)($id, 'PHP on the new server', 'Use 8.3 on the new box.');

    Mail::assertSent(ClientReply::class, function (ClientReply $mail): bool {
        return $mail->hasTo('ada@northwharf.example')
            && $mail->hasFrom('desk@example.com')
            && $mail->subjectLine === 'PHP on the new server'
            && $mail->replyBody === 'Use 8.3 on the new box.';
    });

    expect(app(ListOpenReplies::class)(london('2026-10-23 15:30')))->toBeEmpty()
        ->and(Draft::query()->count())->toBe(0);
});

it('leaves the row and the draft when the mail server refuses', function () {
    Mail::extend('refused', function () {
        return new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('refused');
            }

            public function __toString(): string
            {
                return 'refused';
            }
        };
    });

    config([
        'mail.default' => 'refused',
        'mail.mailers.refused' => ['transport' => 'refused'],
    ]);

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3 on the new box.');

    expect(fn () => app(SendReply::class)($id, 'Edited subject', 'Edited body.'))
        ->toThrow(DeskException::class, 'The mail server refused the message.');

    $open = app(ListOpenReplies::class)(london('2026-10-23 15:30'));

    expect($open)->toHaveCount(1)
        ->and($open[0]->replyDue->format('Y-m-d H:i'))->toBe('2026-10-21 10:00')
        ->and($open[0]->draftSubject)->toBe('PHP on the new server')
        ->and($open[0]->draftBody)->toBe('Use 8.3 on the new box.');
});

it('sends the draft from the queue', function () {
    Mail::fake();
    $this->actingAs(User::factory()->create());

    $id = app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-20 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
    );

    app(SaveDraft::class)($id, 'PHP on the new server', 'Use 8.3 on the new box.');

    Livewire::test(OpenQueue::class)
        ->mountAction(TestAction::make('send')->table($id))
        ->assertSchemaStateSet([
            'subject' => 'PHP on the new server',
            'body' => 'Use 8.3 on the new box.',
        ])
        ->callMountedAction()
        ->assertSee('No open replies');

    Mail::assertSent(ClientReply::class);
    expect(Draft::query()->count())->toBe(0);
});
