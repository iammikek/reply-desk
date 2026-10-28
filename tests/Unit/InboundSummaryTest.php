<?php

declare(strict_types=1);

use App\Desk\InboundSummary;

it('prefers the subject', function (): void {
    expect(InboundSummary::from('The loaf', "Body first line\nMore"))->toBe('The loaf');
});

it('uses the first non-empty body line when subject is blank', function (): void {
    expect(InboundSummary::from('', "\n\n  Can you look today?\nQuoted"))->toBe('Can you look today?');
});

it('returns an empty string when both are blank', function (): void {
    expect(InboundSummary::from('  ', "\n\n"))->toBe('');
});
