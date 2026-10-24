<?php

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Desk\DeskException;
use App\Desk\Notes\NoteStore;
use App\Models\Draft;

final class SaveDraft
{
    public function __construct(private NoteStore $notes) {}

    public function __invoke(string $inboundId, string $subject, string $body): void
    {
        $subject = trim($subject);
        $body = ClockNote::pasted($body);

        if ($subject === '' || $body === '' || str_contains($subject, "\n") || str_contains($subject, "\r")) {
            throw new DeskException('A draft needs a subject and a body.');
        }

        $this->openInbound($inboundId);

        Draft::query()->updateOrCreate(
            ['inbound_note_id' => $inboundId],
            ['subject' => $subject, 'body' => $body],
        );
    }

    private function openInbound(string $inboundId): void
    {
        $found = false;

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent && $parsed->closes === $inboundId) {
                throw new DeskException("Inbound {$inboundId} is already closed.");
            }

            if ($note->id === $inboundId && $parsed instanceof ParsedInbound) {
                $found = true;
            }
        }

        if (! $found) {
            throw new DeskException("No open inbound {$inboundId}.");
        }
    }
}
