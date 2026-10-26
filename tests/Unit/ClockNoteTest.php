<?php

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReceiptSource;

it('parses a recorded inbound note', function () {
    $note = clockNoteFixture('inbound-note.json');
    $parsed = ClockNote::parse($note['properties']['hs_note_body']);

    expect($parsed)->toBeInstanceOf(ParsedInbound::class)
        ->and($parsed->receipt->format('Y-m-d H:i'))->toBe('2026-10-22 10:42')
        ->and($parsed->receiptSource)->toBe(ReceiptSource::Logged)
        ->and($parsed->summary)->toBe('Newer OS, more disk, and which PHP to use');
});

it('parses the inbound shape when the summary line is absent', function () {
    $note = clockNoteFixture('inbound-without-summary.json');
    $parsed = ClockNote::parse($note['properties']['hs_note_body']);

    expect($parsed)->toBeInstanceOf(ParsedInbound::class)
        ->and($parsed->receiptSource)->toBe(ReceiptSource::MailClient)
        ->and($parsed->summary)->toBe('');
});

it('parses a recorded sent note', function () {
    $note = clockNoteFixture('sent-note.json');
    $parsed = ClockNote::parse($note['properties']['hs_note_body']);

    expect($parsed)->toBeInstanceOf(ParsedSent::class)
        ->and($parsed->closes)->toBe('524192418041')
        ->and($parsed->sent->format('Y-m-d H:i'))->toBe('2026-10-22 16:04')
        ->and($parsed->subject)->toBe('PHP on the new server');
});

it('ignores an ordinary progress note', function () {
    $note = clockNoteFixture('progress-note.json');

    expect(ClockNote::parse($note['properties']['hs_note_body']))->toBeNull();
});

it('ignores a note that only mentions the clock in prose', function () {
    expect(ClockNote::parse("The clock starts when the mail arrives.\nCloses: 1"))->toBeNull();
});

it('writes a pasted WhatsApp message that parses back', function () {
    $receipt = new DateTimeImmutable('2026-10-26 11:15', new DateTimeZone('Europe/London'));
    $body = ClockNote::inbound($receipt, ReceiptSource::WhatsApp, 'The form can come off');
    $parsed = ClockNote::parse($body);

    expect($parsed)->toBeInstanceOf(ParsedInbound::class)
        ->and($parsed->receiptSource)->toBe(ReceiptSource::WhatsApp)
        ->and($parsed->receiptSource->label())->toBe('WhatsApp')
        ->and($body)->toContain('Receipt source: whatsapp');
});

it('writes an inbound note that parses back', function () {
    $receipt = new DateTimeImmutable('2026-10-22 10:42', new DateTimeZone('Europe/London'));
    $body = ClockNote::inbound($receipt, ReceiptSource::MailClient, 'Which PHP to use');
    $parsed = ClockNote::parse($body);

    expect($parsed)->toBeInstanceOf(ParsedInbound::class)
        ->and($parsed->summary)->toBe('Which PHP to use')
        ->and($body)->toBe(<<<'TXT'
        Clock: inbound
        Receipt: 2026-10-22 10:42 Europe/London
        Receipt source: mail client
        Summary: Which PHP to use
        TXT);
});

it('writes an inbound subject and body as separate parts', function () {
    $body = ClockNote::withMail(
        ClockNote::inbound(london('2026-10-22 10:42'), ReceiptSource::MailClient, '', 'The form can come off'),
        "The form can come off the front.\nCan you look today?",
    );
    $parsed = ClockNote::parse($body);

    expect($parsed)->toBeInstanceOf(ParsedInbound::class)
        ->and($parsed->summary)->toBe('The form can come off')
        ->and(ClockNote::correspondence($body)['client'])->toBe("The form can come off the front.\nCan you look today?")
        ->and($body)->toContain('Subject: The form can come off')
        ->and($body)->not->toContain('Summary:');
});

it('keeps the clock header when the client email is filed on the note', function () {
    $body = ClockNote::withMail(
        ClockNote::inbound(london('2026-10-22 10:42'), ReceiptSource::Logged, 'Which PHP to use'),
        'Which loaf for Friday.',
        'Use the tin loaf.',
    );
    $parsed = ClockNote::parse($body);

    expect($parsed)->toBeInstanceOf(ParsedInbound::class)
        ->and($parsed->summary)->toBe('Which PHP to use')
        ->and(ClockNote::correspondence($body)['client'])->toBe('Which loaf for Friday.')
        ->and(ClockNote::correspondence($body)['reply'])->toBe('Use the tin loaf.');
});

it('keeps a pasted client email and reply', function () {
    $client = "Hello Ada,\r\n\r\n    The loaf is  wholemeal.";
    $reply = "Thanks.\n\n    I will  bake it.  ";
    $body = ClockNote::withMail(
        ClockNote::inbound(london('2026-10-22 10:42'), ReceiptSource::MailClient, '', 'The loaf'),
        $client,
        $reply,
    );

    expect(ClockNote::correspondence($body)['client'])->toBe("Hello Ada,\n\n    The loaf is  wholemeal.")
        ->and(ClockNote::correspondence($body)['reply'])->toBe($reply)
        ->and(ClockNote::parse($body)?->summary)->toBe('The loaf');
});

it('keeps a pasted reply on a sent note', function () {
    $reply = "Thanks.\n\n    I will  bake it.";
    $body = ClockNote::sent('123456789', london('2026-10-22 16:04'), 'The loaf', $reply);

    expect(ClockNote::correspondence($body)['reply'])->toBe($reply)
        ->and(ClockNote::parse($body)?->subject)->toBe('The loaf');
});

it('omits a blank pasted section', function () {
    $body = ClockNote::withMail(
        ClockNote::inbound(london('2026-10-22 10:42'), ReceiptSource::MailClient, 'The loaf'),
        "   \n",
        'Thanks.',
    );

    expect(ClockNote::correspondence($body)['client'])->toBe('')
        ->and($body)->not->toContain('Client email:')
        ->and(ClockNote::correspondence($body)['reply'])->toBe('Thanks.');
});

it('rejects a subject that runs over more than one line', function () {
    ClockNote::inbound(london('2026-10-22 10:42'), ReceiptSource::MailClient, '', "The loaf\nToday");
})->throws(InvalidArgumentException::class, 'Subject must be one line.');

it('writes a sent note that parses back', function () {
    $sent = new DateTimeImmutable('2026-10-22 16:04', new DateTimeZone('Europe/London'));
    $body = ClockNote::sent('123456789', $sent, 'PHP on the new server');
    $parsed = ClockNote::parse($body);

    expect($parsed)->toBeInstanceOf(ParsedSent::class)
        ->and($parsed->closes)->toBe('123456789')
        ->and($body)->toContain('Subject: PHP on the new server');
});

/**
 * @return array<string, mixed>
 */
function clockNoteFixture(string $name): array
{
    $json = file_get_contents(__DIR__.'/../Fixtures/hubspot/'.$name);

    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}
