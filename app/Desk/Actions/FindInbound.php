<?php

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Desk\DeskException;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;

final readonly class FindInbound
{
    public function __construct(private NoteStore $notes) {}

    public function open(string $inboundId): StoredNote
    {
        return $this->find($inboundId, requireOpen: true);
    }

    public function note(string $inboundId): StoredNote
    {
        return $this->find($inboundId, requireOpen: false);
    }

    private function find(string $inboundId, bool $requireOpen): StoredNote
    {
        $found = null;
        $closed = false;

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent && $parsed->closes === $inboundId) {
                $closed = true;
            }

            if ($note->id === $inboundId && $parsed instanceof ParsedInbound) {
                $found = $note;
            }
        }

        if (! $found instanceof StoredNote) {
            throw new DeskException("No open inbound {$inboundId}.");
        }

        if ($requireOpen && $closed) {
            throw new DeskException("Inbound {$inboundId} is already closed.");
        }

        return $found;
    }
}
