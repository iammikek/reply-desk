<?php

use App\Desk\Mailbox\ExtractLatestMessage;

it('keeps only the top segment before greater-than quotes', function (): void {
    $body = "Thanks for this.\n\n> Earlier message\n> still quoted";

    expect(app(ExtractLatestMessage::class)($body))->toBe('Thanks for this.');
});

it('strips an On wrote block', function (): void {
    $body = "Will do.\n\nOn 22 Oct 2026, at 10:00, Ada wrote:\nHello";

    expect(app(ExtractLatestMessage::class)($body))->toBe('Will do.');
});

it('strips nested quote markers', function (): void {
    $body = "Latest note.\n\n> Outer\n>> Inner";

    expect(app(ExtractLatestMessage::class)($body))->toBe('Latest note.');
});

it('converts html-only mail and drops a blockquote', function (): void {
    $html = '<p>New answer.</p><blockquote>Old thread</blockquote>';

    expect(app(ExtractLatestMessage::class)('', $html))->toBe('New answer.');
});

it('returns empty when only quotes remain', function (): void {
    $body = '> Only a quote';

    expect(app(ExtractLatestMessage::class)($body))->toBe('');
});
