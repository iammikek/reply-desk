<?php

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Desk\DeskException;
use App\Desk\Notes\NoteFile;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;
use App\Models\Draft;
use DateTimeImmutable;

final class MarkReplySent
{
    public function __construct(private NoteStore $notes) {}

    public function __invoke(string $inboundId, DateTimeImmutable $sent, string $subject, string $reply = '', ?NoteFile $file = null): string
    {
        $inbound = null;

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent && $parsed->closes === $inboundId) {
                throw new DeskException("Inbound {$inboundId} is already closed.");
            }

            if ($note->id === $inboundId && $parsed instanceof ParsedInbound) {
                $inbound = $note;
            }
        }

        if (! $inbound instanceof StoredNote) {
            throw new DeskException("No open inbound {$inboundId}.");
        }

        $id = $this->notes->writeNote(
            ClockNote::sent($inboundId, $sent, $subject, $reply),
            $inbound->companyId,
            $inbound->contactId,
            $file,
        );

        Draft::query()->where(Draft::forInbound($inboundId))->delete();

        return $id;
    }
}
