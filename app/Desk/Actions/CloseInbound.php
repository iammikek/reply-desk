<?php

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Clock\ReplyClock;
use App\Desk\LogDeskActivity;
use App\Desk\Notes\NoteStore;
use App\Models\Draft;
use DateTimeImmutable;

final readonly class CloseInbound
{
    public function __construct(
        private NoteStore $notes,
        private FindInbound $findInbound,
        private LogDeskActivity $log,
    ) {}

    public function __invoke(string $inboundId, ?DateTimeImmutable $closed = null): string
    {
        $inbound = ($this->findInbound)->open($inboundId);
        $closed ??= DateTimeImmutable::createFromInterface(now(ReplyClock::zoneName()));

        $id = $this->notes->writeNote(
            ClockNote::closed($inboundId, $closed),
            $inbound->companyId,
            $inbound->contactId,
        );

        Draft::deleteForInbound($inboundId);

        ($this->log)(
            'inbound.closed',
            'Inbound closed without a reply.',
            inboundId: $inboundId,
            noteId: $id,
            company: $inbound->companyName,
            contact: $inbound->contactName,
            companyId: $inbound->companyId,
            contactId: $inbound->contactId,
        );

        return $id;
    }
}
