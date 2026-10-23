<?php

namespace App\Desk;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReplyClock;
use App\Models\Draft;
use DateTimeImmutable;

final class ListOpenReplies
{
    public function __construct(
        private NoteStore $notes,
        private ReplyClock $clock,
    ) {}

    /**
     * @return list<OpenReply>
     */
    public function __invoke(?DateTimeImmutable $now = null): array
    {
        $now ??= DateTimeImmutable::createFromInterface(now(ReplyClock::ZONE));
        $closed = [];
        $inbound = [];

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent) {
                $closed[$parsed->closes] = true;
            }

            if ($parsed instanceof ParsedInbound) {
                $inbound[] = [$note, $parsed];
            }
        }

        $open = [];
        $drafts = Draft::query()
            ->whereIn('inbound_note_id', array_map(fn (array $row): string => $row[0]->id, $inbound))
            ->get()
            ->keyBy('inbound_note_id');

        foreach ($inbound as [$note, $parsed]) {
            if (isset($closed[$note->id])) {
                continue;
            }

            $schedule = $this->clock->schedule($parsed->receipt);
            $contact = $note->contactName !== '' ? $note->contactName : $note->contactEmail;
            $draft = $drafts->get($note->id);

            $open[] = new OpenReply(
                $note->id,
                trim($note->companyName.' / '.$contact, ' /'),
                $parsed->summary,
                $note->contactEmail,
                $note->companyId,
                $note->contactId,
                $parsed->receipt,
                $schedule->clockStart,
                $schedule->replyDue,
                $parsed->receiptSource,
                $note->body,
                $draft?->subject,
                $draft?->body,
            );
        }

        return $open;
    }
}
