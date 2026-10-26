<?php

namespace App\Desk;

use App\Clock\ClockNote;
use App\Models\Draft;

final class SavedDraft
{
    public static function unchanged(string $inboundId, string $subject, string $body): bool
    {
        $draft = Draft::query()->where(Draft::forInbound($inboundId))->first();

        if (! $draft instanceof Draft) {
            return false;
        }

        return trim($subject) === $draft->subject && ClockNote::pasted($body) === $draft->body;
    }
}
