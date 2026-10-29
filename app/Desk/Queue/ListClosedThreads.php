<?php

namespace App\Desk\Queue;

use App\Clock\ClockNote;
use App\Desk\Notes\NoteStore;
use DateTimeImmutable;

final readonly class ListClosedThreads
{
    public function __construct(private NoteStore $notes) {}

    /**
     * @return list<ClosedThread>
     */
    public function __invoke(): array
    {
        ['inbound' => $inbound, 'sent' => $sent, 'closed' => $closed] = (new IndexClockNotes($this->notes))();
        $threads = [];

        foreach ($inbound as [$note, $parsed]) {
            $replies = $sent[$note->id] ?? null;
            $closure = $closed[$note->id] ?? null;

            if ($replies === null && $closure === null) {
                continue;
            }

            $sorted = $replies === null ? [] : IndexClockNotes::sortReplies($replies);

            $threads[] = new ClosedThread(
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
                ], $sorted),
                $note->attachmentName,
                $note->companyId,
                $note->contactId,
                $closure === null ? null : $closure[1]->closed,
            );
        }

        usort($threads, function (ClosedThread $left, ClosedThread $right): int {
            return $this->endedAt($right) <=> $this->endedAt($left);
        });

        return $threads;
    }

    private function endedAt(ClosedThread $thread): DateTimeImmutable
    {
        if ($thread->replies !== []) {
            return $thread->replies[array_key_last($thread->replies)]['sent'];
        }

        if ($thread->closedAt instanceof DateTimeImmutable) {
            return $thread->closedAt;
        }

        return $thread->receipt;
    }
}
