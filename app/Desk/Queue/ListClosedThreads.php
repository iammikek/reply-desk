<?php

namespace App\Desk\Queue;

use App\Clock\ClockNote;
use App\Desk\Notes\NoteStore;

final readonly class ListClosedThreads
{
    public function __construct(private NoteStore $notes) {}

    /**
     * @return list<ClosedThread>
     */
    public function __invoke(): array
    {
        ['inbound' => $inbound, 'sent' => $sent] = (new IndexClockNotes($this->notes))();
        $closed = [];

        foreach ($inbound as [$note, $parsed]) {
            $replies = $sent[$note->id] ?? null;

            if ($replies === null) {
                continue;
            }

            $replies = IndexClockNotes::sortReplies($replies);

            $closed[] = new ClosedThread(
                $note->id,
                $note->clientLabel(),
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
                $note->companyId,
                $note->contactId,
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
