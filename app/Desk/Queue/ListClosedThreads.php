<?php

namespace App\Desk\Queue;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Desk\Notes\NoteStore;

final class ListClosedThreads
{
    public function __construct(private NoteStore $notes) {}

    /**
     * @return list<ClosedThread>
     */
    public function __invoke(): array
    {
        $sent = [];
        $inbound = [];

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent) {
                $sent[$parsed->closes][] = [$note, $parsed];
            }

            if ($parsed instanceof ParsedInbound) {
                $inbound[] = [$note, $parsed];
            }
        }

        $closed = [];

        foreach ($inbound as [$note, $parsed]) {
            $replies = $sent[$note->id] ?? null;

            if ($replies === null) {
                continue;
            }

            usort($replies, fn (array $left, array $right): int => $left[1]->sent <=> $right[1]->sent);

            $contact = $note->contactName !== '' ? $note->contactName : $note->contactEmail;

            $closed[] = new ClosedThread(
                $note->id,
                trim($note->companyName.' / '.$contact, ' /'),
                $note->contactEmail,
                $parsed->receipt,
                $parsed->summary,
                ClockNote::correspondence($note->body)['client'],
                array_map(fn (array $reply): array => [
                    'sent' => $reply[1]->sent,
                    'subject' => $reply[1]->subject,
                    'body' => ClockNote::correspondence($reply[0]->body)['reply'],
                    'attachment' => $reply[0]->attachmentName,
                    'note_id' => $reply[0]->id,
                ], $replies),
                $note->attachmentName,
            );
        }

        usort($closed, function (ClosedThread $left, ClosedThread $right): int {
            $leftSent = $left->replies[array_key_last($left->replies)]['sent'];
            $rightSent = $right->replies[array_key_last($right->replies)]['sent'];

            return $rightSent <=> $leftSent;
        });

        return $closed;
    }
}
